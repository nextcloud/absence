<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Listener;

use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Service\CalendarService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IDBConnection;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Removes a deleted user's leave data for GDPR compliance (spec §17).
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	/** Sentinel that replaces a purged user's uid on history/attachment rows about other people. */
	private const DELETED_ACTOR = 'deleted_user';

	public function __construct(
		private IDBConnection $db,
		private LeaveRequestMapper $requestMapper,
		private CalendarService $calendar,
		private \OCA\Absence\Service\AttachmentService $attachments,
		private LoggerInterface $logger,
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
			try {
				$this->calendar->onRemoved($request);
			} catch (\Throwable $e) {
				$this->logger->warning('Absence: could not remove calendar events for purged user', ['exception' => $e]);
			}
		}

		// Remove comments and history events on the user's requests, plus comments
		// the user authored elsewhere.
		$requestIds = $this->requestIdsForEmployee($uid);
		if ($requestIds !== []) {
			$this->deleteWhereIn('absence_comments', 'request_id', $requestIds, IQueryBuilder::PARAM_INT_ARRAY);
			$this->deleteWhereIn('absence_request_events', 'request_id', $requestIds, IQueryBuilder::PARAM_INT_ARRAY);
			// Attachments carry the most sensitive payload of all (§3.8): purge
			// the stored bytes along with the rows.
			try {
				$this->attachments->purgeForRequests($requestIds);
			} catch (\Throwable $e) {
				$this->logger->warning('Absence: could not purge attachments for deleted user', ['exception' => $e]);
			}
		}
		$this->deleteWhereEquals('absence_comments', 'author_uid', $uid);

		// Remove the user's requests and entitlements, and the record of who changed
		// those entitlements — it names the employee and is about their allowance, so
		// it goes with them (§17).
		$this->deleteWhereEquals('absence_requests', 'employee_uid', $uid);
		$this->deleteWhereEquals('absence_entitlements', 'employee_uid', $uid);
		$this->deleteWhereEquals('absence_entitlement_events', 'employee_uid', $uid);

		// Detach the user as a manager or replacement from any remaining requests.
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
		// uploader, so anonymize that identity to a sentinel (the columns are notnull,
		// so the uid cannot simply be nulled). Mirrors the comment cleanup above (§17).
		$this->anonymizeActor('absence_request_events', 'actor_uid', $uid);
		$this->anonymizeActor('absence_entitlement_events', 'actor_uid', $uid);
		$this->anonymizeActor('absence_attachments', 'uploader_uid', $uid);
	}

	/** Replace a deleted user's uid, where they are the actor on a surviving row, with a sentinel. */
	private function anonymizeActor(string $table, string $column, string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set($column, $qb->createNamedParameter(self::DELETED_ACTOR))
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
