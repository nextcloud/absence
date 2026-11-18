<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\EntitlementEvent;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\RequestEvent;
use OCP\AppFramework\Db\TTransactional;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/**
 * Removes the leave data of a deleted account (§17). Running it again for the
 * same uid finishes what a failed run left.
 */
class UserPurgeService {
	use TTransactional;

	/** The columns that name somebody, per table, for {@see findOrphanUids()}. */
	private const UID_COLUMNS = [
		'absence_requests' => ['employee_uid', 'manager_uid', 'replacement_uid', 'decided_by'],
		'absence_comments' => ['author_uid'],
		'absence_comment_mails' => ['recipient_uid'],
		'absence_request_events' => ['actor_uid'],
		'absence_entitlements' => ['employee_uid'],
		'absence_ent_events' => ['employee_uid', 'actor_uid'],
		'absence_attachments' => ['uploader_uid'],
		'absence_employments' => ['employee_uid'],
		'absence_periods' => ['employee_uid'],
		'absence_emp_history' => ['employee_uid', 'actor_uid'],
		'absence_holidays' => ['created_by', 'updated_by'],
		'absence_holiday_history' => ['actor_uid'],
	];
	/** The actor columns that also hold the jobs' own actor, which is no account. */
	private const JOB_ACTORS = [
		'absence_request_events' => RequestEvent::ACTOR_SYSTEM,
		'absence_ent_events' => EntitlementEvent::ACTOR_SYSTEM,
	];

	public function __construct(
		private IDBConnection $db,
		private LeaveRequestMapper $requestMapper,
		private AttachmentService $attachments,
		private LoggerInterface $logger,
		private EmployeeService $employees,
		private RequestService $requests,
		private NotificationService $notifications,
		private IEventDispatcher $dispatcher,
		private IUserManager $userManager,
	) {
	}

	/**
	 * @throws \Throwable when the attachments or the rows could not be removed;
	 *                    nothing of the rows is removed then
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
		// The comment notifications on other people's requests quote what the user wrote.
		foreach ($this->commentedRequestIds($uid) as $requestId) {
			$this->notifications->dismissComments($requestId);
		}

		// Attachments carry the most sensitive payload of all (§3.8). Files cannot be
		// rolled back, so they go first and outside the transaction: one that cannot
		// be deleted stops the purge while the request rows still lead to it.
		$requestIds = $this->requestIdsForEmployee($uid);
		if ($requestIds !== []) {
			$this->attachments->purgeForRequests($requestIds);
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
	 * The uids the Absence tables name that no account has, sorted.
	 *
	 * @return list<string>
	 */
	public function findOrphanUids(): array {
		$uids = [];
		foreach (self::UID_COLUMNS as $table => $columns) {
			foreach ($columns as $column) {
				$qb = $this->db->getQueryBuilder();
				$qb->selectDistinct($column)
					->from($table)
					->where($qb->expr()->isNotNull($column));
				if ($column === 'actor_uid' && isset(self::JOB_ACTORS[$table])) {
					$qb->andWhere($qb->expr()->neq($column, $qb->createNamedParameter(self::JOB_ACTORS[$table])));
				}
				$result = $qb->executeQuery();
				foreach ($result->fetchFirstColumn() as $uid) {
					$uids[(string)$uid] = true;
				}
				$result->closeCursor();
			}
		}
		$orphans = array_values(array_filter(array_map(strval(...), array_keys($uids)), fn (string $uid): bool => !$this->userManager->userExists($uid)));
		sort($orphans);
		return $orphans;
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
		// uploader, so that identity is removed. Mirrors the comment cleanup above (§17).
		// The two histories cannot tell an account with the jobs' own uid from
		// the jobs, so those rows stay as the jobs'.
		foreach (self::JOB_ACTORS as $table => $jobActor) {
			if ($uid !== $jobActor) {
				$this->anonymizeActor($table, 'actor_uid', $uid);
			}
		}
		$this->anonymizeActor('absence_attachments', 'uploader_uid', $uid);
		$this->anonymizeActor('absence_emp_history', 'actor_uid', $uid);
		$this->anonymizeActor('absence_requests', 'decided_by', $uid);
		$this->anonymizeActor('absence_holidays', 'created_by', $uid);
		$this->anonymizeActor('absence_holidays', 'updated_by', $uid);
		$this->anonymizeActor('absence_holiday_history', 'actor_uid', $uid);
		return $awaitingDecision;
	}

	/** Null a deleted user's uid, where they are the actor on a surviving row. */
	private function anonymizeActor(string $table, string $column, string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set($column, $qb->createNamedParameter(null))
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

	/**
	 * @return list<int>
	 */
	private function commentedRequestIds(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('request_id')
			->from('absence_comments')
			->where($qb->expr()->eq('author_uid', $qb->createNamedParameter($uid)));
		$result = $qb->executeQuery();
		$ids = array_map(intval(...), $result->fetchFirstColumn());
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
