<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCP\AppFramework\Db\TTransactional;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/**
 * Removes the leave data of a deleted account (§17). Running it again for the
 * same uid finishes what a failed run left.
 */
class UserPurgeService {
	use TTransactional;

	/** Replaces a purged user's uid on history/attachment rows about other people, where the column cannot be null. */
	private const DELETED_ACTOR = 'deleted_user';

	public function __construct(
		private IDBConnection $db,
		private LeaveRequestMapper $requestMapper,
		private AttachmentService $attachments,
		private LoggerInterface $logger,
		private EmployeeService $employees,
		private RequestService $requests,
		private NotificationService $notifications,
		private IEventDispatcher $dispatcher,
	) {
	}

	/**
	 * @throws \Throwable when the rows could not be removed; none of them is then
	 */
	public function purge(string $uid): void {
		$this->logger->info('Absence action: user_data_purged', [
			'app' => 'absence',
			'action' => 'user_data_purged',
			'employee' => $uid,
		]);

		// Other people's notifications about the user's requests go with them.
		foreach ($this->requestMapper->findAllForEmployee($uid) as $request) {
			$this->notifications->dismiss($request);
		}

		// Attachments carry the most sensitive payload of all (§3.8): purge the
		// stored bytes along with the rows. Files cannot be rolled back, so this
		// stays outside the transaction.
		$requestIds = $this->requestIdsForEmployee($uid);
		if ($requestIds !== []) {
			try {
				$this->attachments->purgeForRequests($requestIds);
			} catch (\Throwable $e) {
				$this->logger->warning('Absence: could not purge attachments for deleted user', ['exception' => $e]);
			}
		}

		$awaitingDecision = $this->atomic(fn (): array => $this->purgeRows($uid, $requestIds), $this->db);
		// The requests the user was still to decide go to HR, once the purge is
		// committed. Best-effort: the escalation job picks up what fails here.
		foreach ($awaitingDecision as $request) {
			// As the row now reads, so that the audit log does not name the user
			$request->setManagerUid(null);
			try {
				$this->requests->handOverToHr($request);
			} catch (\Throwable $e) {
				$this->logger->warning('Absence: could not hand a request of a deleted manager over to HR', ['exception' => $e]);
			}
		}
		$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent('Absence data of %s purged', [$uid]));
	}

	/**
	 * @param int[] $requestIds the user's own requests
	 * @return list<LeaveRequest> the requests the user was still to decide
	 */
	private function purgeRows(string $uid, array $requestIds): array {
		// Remove comments and history events on the user's requests, plus comments
		// the user authored elsewhere.
		if ($requestIds !== []) {
			$this->deleteWhereIn('absence_comments', 'request_id', $requestIds, IQueryBuilder::PARAM_INT_ARRAY);
			$this->deleteWhereIn('absence_comment_mails', 'request_id', $requestIds, IQueryBuilder::PARAM_INT_ARRAY);
			$this->deleteWhereIn('absence_request_events', 'request_id', $requestIds, IQueryBuilder::PARAM_INT_ARRAY);
		}
		$this->deleteWhereEquals('absence_comments', 'author_uid', $uid);
		// The copy of those comments that HR reads in the history (§3.7)
		$qb = $this->db->getQueryBuilder();
		$qb->update('absence_request_events')
			->set('hr_detail', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('actor_uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('event_type', $qb->createNamedParameter('comment_added')));
		$qb->executeStatement();
		// Comment mails still waiting for the user
		$this->deleteWhereEquals('absence_comment_mails', 'recipient_uid', $uid);

		// Remove the user's requests and entitlements, and the record of who changed
		// those entitlements: it names the employee and is about their allowance, so
		// it goes with them (§17).
		$this->deleteWhereEquals('absence_requests', 'employee_uid', $uid);
		$this->deleteWhereEquals('absence_entitlements', 'employee_uid', $uid);
		$this->deleteWhereEquals('absence_ent_events', 'employee_uid', $uid);
		// Their contract data and its history go too (§6.4).
		$this->employees->purge($uid);

		// Detach the user as a manager or replacement from any remaining requests.
		$awaitingDecision = $this->requestMapper->findPendingAssignedTo($uid);
		foreach (['manager_uid', 'replacement_uid'] as $column) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('absence_requests')
				->set($column, $qb->createNamedParameter(null))
				->where($qb->expr()->eq($column, $qb->createNamedParameter($uid)));
			$qb->executeStatement();
		}

		// The rows above removed everything *about* the user. What remains is where
		// the user acted on *someone else's* record: approved/rejected/commented on
		// another employee's request, adjusted their entitlement, uploaded a note to
		// their request. Those history/attachment rows belong to the other employee's
		// audit trail and must stay, but they still name the deleted person as actor/
		// uploader, so that identity is removed: null where the column allows it, the
		// sentinel where it is notnull. Mirrors the comment cleanup above (§17).
		$this->anonymizeActor('absence_request_events', 'actor_uid', $uid, self::DELETED_ACTOR);
		$this->anonymizeActor('absence_ent_events', 'actor_uid', $uid, self::DELETED_ACTOR);
		$this->anonymizeActor('absence_attachments', 'uploader_uid', $uid, self::DELETED_ACTOR);
		$this->anonymizeActor('absence_emp_history', 'actor_uid', $uid, null);
		$this->anonymizeActor('absence_requests', 'decided_by', $uid, null);
		$this->anonymizeActor('absence_holidays', 'created_by', $uid, null);
		$this->anonymizeActor('absence_holidays', 'updated_by', $uid, null);
		$this->anonymizeActor('absence_holiday_history', 'actor_uid', $uid, null);
		return $awaitingDecision;
	}

	/** Replace a deleted user's uid, where they are the actor on a surviving row. */
	private function anonymizeActor(string $table, string $column, string $uid, ?string $marker): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set($column, $qb->createNamedParameter($marker))
			->where($qb->expr()->eq($column, $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}

	/**
	 * @return int[]
	 */
	private function requestIdsForEmployee(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('absence_requests')
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($uid)));
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}

	private function deleteWhereEquals(string $table, string $column, string $value): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter($value)));
		$qb->executeStatement();
	}

	/**
	 * @param int[] $values
	 */
	private function deleteWhereIn(string $table, string $column, array $values, int $type): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($values, $type)));
		$qb->executeStatement();
	}
}
