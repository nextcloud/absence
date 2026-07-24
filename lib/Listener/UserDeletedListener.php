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
	public function __construct(
		private IDBConnection $db,
		private LeaveRequestMapper $requestMapper,
		private CalendarService $calendar,
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
		}
		$this->deleteWhereEquals('absence_comments', 'author_uid', $uid);

		// Remove the user's requests and entitlements.
		$this->deleteWhereEquals('absence_requests', 'employee_uid', $uid);
		$this->deleteWhereEquals('absence_entitlements', 'employee_uid', $uid);

		// Detach the user as a manager or replacement from any remaining requests.
		foreach (['manager_uid', 'replacement_uid'] as $column) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('absence_requests')
				->set($column, $qb->createNamedParameter(null))
				->where($qb->expr()->eq($column, $qb->createNamedParameter($uid)));
			$qb->executeStatement();
		}
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
