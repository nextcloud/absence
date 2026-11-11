<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Listener;

use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Service\CalendarService;
use OCA\Absence\Service\EmployeeService;
use OCA\Absence\Service\NotificationService;
use OCA\Absence\Service\RequestService;
use OCP\AppFramework\Db\TTransactional;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\IDBConnection;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Removes a deleted user's leave data for GDPR compliance (spec §17).
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	use TTransactional;

	/** Replaces a purged user's uid on history/attachment rows about other people, where the column cannot be null. */
	private const DELETED_ACTOR = 'deleted_user';

	public function __construct(
		private IDBConnection $db,
		private LeaveRequestMapper $requestMapper,
		private CalendarService $calendar,
		private \OCA\Absence\Service\AttachmentService $attachments,
		private LoggerInterface $logger,
		private EmployeeService $employees,
		private RequestService $requests,
		private NotificationService $notifications,
		private IEventDispatcher $dispatcher,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$uid = $event->getUser()->getUID();
		$this->logger->info('Absence action: user_data_purged', [
			'app' => 'absence',
			'action' => 'user_data_purged',
			'employee' => $uid,
		]);

		// Remove the calendar events of the user's leave *before* the request rows go:
		// they hold the calendar_event_uri references, and the shared team calendar
		// would otherwise keep "Name — Absent" events forever. Best-effort: a calendar
		// failure must not block the purge.
		foreach ($this->requestMapper->findAllForEmployee($uid) as $request) {
			// Other people's notifications about these requests go with them.
			$this->notifications->dismiss($request);
			try {
				$this->calendar->onRemoved($request);
			} catch (\Throwable $e) {
				$this->logger->warning('Absence: could not remove calendar events for purged user', ['exception' => $e]);
			}
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
		// committed. Best-effort, like the calendar cleanup: the escalation job
		// picks up what fails here.
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
	 * @return list<\OCA\Absence\Db\LeaveRequest> the requests the user was still to decide
	 */
	private function purgeRows(string $uid, array $requestIds): array {
		// Remove comments and history events on the user's requests, plus comments
		// the user authored elsewhere.
		if ($requestIds !== []) {
			$this->deleteWhereIn('absence_comments', 'request_id', $requestIds, IQueryBuilder::PARAM_INT_ARRAY);
			$this->deleteWhereIn('absence_request_events', 'request_id', $requestIds, IQueryBuilder::PARAM_INT_ARRAY);
		}
		$this->deleteWhereEquals('absence_comments', 'author_uid', $uid);

		// Remove the user's requests and entitlements, and the record of who changed
		// those entitlements — it names the employee and is about their allowance, so
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
		// the user acted on *someone else's* record — approved/rejected/commented on
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
