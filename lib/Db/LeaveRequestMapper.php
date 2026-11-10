<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<LeaveRequest>
 */
class LeaveRequestMapper extends QBMapper {
	// Oracle accepts at most 1000 values in one IN() list.
	private const IN_CHUNK = 1000;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'absence_requests', LeaveRequest::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): LeaveRequest {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * The row as it is now, locked until the running transaction ends: a second
	 * transaction asking for the same row waits here and then reads what the
	 * first one wrote. Only meaningful inside a transaction.
	 *
	 * SQLite has no row locks and needs none: it lets one transaction write at a
	 * time and fails the one whose read has gone stale.
	 *
	 * @throws DoesNotExistException
	 */
	public function findForUpdate(int $id): LeaveRequest {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
			$qb->forUpdate();
		}
		return $this->findEntity($qb);
	}

	/**
	 * Generic filtered query used by the API list endpoint. An `employeeUids`
	 * filter that is set but empty matches nothing.
	 *
	 * @param array{status?:list<string>,typeId?:int,from?:string,to?:string,employeeUid?:string,employeeUids?:string[]} $filters
	 * @return LeaveRequest[]
	 */
	public function findFiltered(array $filters, ?int $limit = null, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());

		if (isset($filters['employeeUid']) && $filters['employeeUid'] !== '') {
			$qb->andWhere($qb->expr()->eq('employee_uid', $qb->createNamedParameter($filters['employeeUid'])));
		}
		if (isset($filters['employeeUids'])) {
			if ($filters['employeeUids'] === []) {
				return [];
			}
			$qb->andWhere($this->employeeIn($qb, $filters['employeeUids']));
		}
		if (!empty($filters['status'])) {
			$qb->andWhere($qb->expr()->in('status', $qb->createNamedParameter($filters['status'], IQueryBuilder::PARAM_STR_ARRAY)));
		}
		if (!empty($filters['typeId'])) {
			$qb->andWhere($qb->expr()->eq('type_id', $qb->createNamedParameter($filters['typeId'], IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filters['from'])) {
			$qb->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($filters['from'])));
		}
		if (!empty($filters['to'])) {
			$qb->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($filters['to'])));
		}

		// The id tiebreaker keeps the order total: start_date alone is not unique, so
		// without it `limit`/`offset` paging (HR's absence list) could repeat or skip
		// rows whenever several absences share a start date.
		$qb->orderBy('start_date', 'DESC')->addOrderBy('id', 'DESC');
		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}
		return $this->findEntities($qb);
	}

	/**
	 * `employee_uid IN (...)`, split so that no single list exceeds the 1000
	 * entries Oracle accepts.
	 *
	 * @param string[] $employeeUids
	 */
	private function employeeIn(IQueryBuilder $qb, array $employeeUids): ICompositeExpression {
		return $qb->expr()->orX(...array_map(
			static fn (array $chunk) => $qb->expr()->in('employee_uid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)),
			array_chunk($employeeUids, 1000),
		));
	}

	/**
	 * Requests of these employees awaiting a manager's decision. The caller
	 * passes a manager's current direct reports: the `manager_uid` column only
	 * says who the manager was when the request was made (§2.1).
	 *
	 * @param string[] $employeeUids
	 * @return LeaveRequest[]
	 */
	public function findPendingForEmployees(array $employeeUids): array {
		if ($employeeUids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($this->employeeIn($qb, $employeeUids))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				[LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_WITHDRAWAL_PENDING],
				IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('created_at', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Atomically flip one request from PENDING to ESCALATED. Returns whether this
	 * call performed the flip.
	 *
	 * The escalation job picks its candidates from a list read moments earlier,
	 * and a manager may decide one of them in between. An entity update would
	 * write that stale read back over the decision — an approved request flipped
	 * to ESCALATED. Here the status test and the write are one statement, so a
	 * request that is no longer PENDING is left untouched and the caller is told.
	 */
	public function markEscalated(int $id, \DateTime $now): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(LeaveRequest::STATUS_ESCALATED))
			->set('escalated', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(LeaveRequest::STATUS_PENDING)));
		return $qb->executeStatement() === 1;
	}

	/**
	 * {@see findPendingForEmployees()} as a bare count.
	 *
	 * The session bootstrap and the navigation badge only need the number, and
	 * they run on every page load (and after every action) — hydrating full
	 * entities there just to count them was measurable waste.
	 *
	 * @param string[] $employeeUids
	 */
	public function countPendingForEmployees(array $employeeUids): int {
		if ($employeeUids === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($this->employeeIn($qb, $employeeUids))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				[LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_WITHDRAWAL_PENDING],
				IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/** Escalated requests as a bare count, see {@see countPendingForEmployees()}. */
	public function countEscalated(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(LeaveRequest::STATUS_ESCALATED)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Escalated requests + requests without a manager, for the HR queue.
	 *
	 * @return LeaveRequest[]
	 */
	public function findEscalated(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(LeaveRequest::STATUS_ESCALATED)))
			->orderBy('created_at', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Render an instant the way `created_at` is stored.
	 *
	 * The timestamp columns hold UTC ({@see \OCA\Absence\Service\ClockService::now()}),
	 * but the callers of the two queries below work out their cut-offs on the
	 * *server's* calendar — which day boundary a request falls on is a company
	 * question, not a UTC one. Formatting such a cut-off directly would write its
	 * local wall-clock into the query and silently shift every bound by the
	 * server's UTC offset: the reminder window is exactly one working day wide, so
	 * off Berlin's or Auckland's offset it reminds a day early or skips a cohort
	 * entirely. Converting here keeps the working-day arithmetic on the server's
	 * calendar while the comparison happens in the column's own zone.
	 *
	 * Rebuilt from the timestamp rather than via setTimezone() so a caller's
	 * mutable \DateTime is never altered as a side effect.
	 */
	private function asStoredTimestamp(\DateTimeInterface $moment): string {
		return (new \DateTimeImmutable('@' . $moment->getTimestamp()))->format('Y-m-d H:i:s');
	}

	/**
	 * Requests awaiting a decision that were assigned to this manager when they
	 * were made, for handing them over when the account goes away.
	 *
	 * @return LeaveRequest[]
	 */
	public function findPendingAssignedTo(string $managerUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('manager_uid', $qb->createNamedParameter($managerUid)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				[LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_WITHDRAWAL_PENDING],
				IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('created_at', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Pending requests created before the given cut-off (for escalation), with
	 * or without a manager on record.
	 *
	 * @return LeaveRequest[]
	 */
	public function findPendingOlderThan(\DateTimeInterface $cutoff): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(LeaveRequest::STATUS_PENDING)))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter(
				$this->asStoredTimestamp($cutoff), IQueryBuilder::PARAM_STR)));
		return $this->findEntities($qb);
	}

	/**
	 * Non-terminal requests for an employee that overlap the given date range.
	 * Used to reject overlapping submissions (§5.1). Any ids in $excludeIds are
	 * ignored — used to exclude a request and its supersedes-chain counterpart.
	 *
	 * @param int[] $excludeIds
	 * @return LeaveRequest[]
	 */
	public function findOverlapping(string $employeeUid, string $start, string $end, array $excludeIds = []): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($employeeUid)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
				LeaveRequest::ACTIVE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($end)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($start)));
		$excludeIds = array_values(array_filter($excludeIds, static fn ($id) => $id !== null));
		if ($excludeIds !== []) {
			$qb->andWhere($qb->expr()->notIn('id', $qb->createNamedParameter($excludeIds, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Whether the given person has an *approved* absence covering the whole
	 * [$from, $to] range (dates inclusive). Used by §5.4a: a manager who is
	 * away for the entire remaining escalation window cannot possibly decide
	 * in time, so their pending requests route to HR early.
	 */
	public function hasApprovedAbsenceCovering(string $uid, string $from, string $to): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(LeaveRequest::STATUS_APPROVED)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($to)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count > 0;
	}

	/**
	 * Requests that supersede the given request (the pending edits of an approved one).
	 *
	 * @return LeaveRequest[]
	 */
	public function findBySupersedesId(int $supersedesId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('supersedes_id', $qb->createNamedParameter($supersedesId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * Pending requests created in the half-open window [$after, $before) — used to
	 * send a reminder exactly once as a request crosses the reminder threshold.
	 *
	 * @return LeaveRequest[]
	 */
	public function findPendingCreatedBetween(\DateTimeInterface $after, \DateTimeInterface $before): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(LeaveRequest::STATUS_PENDING)))
			->andWhere($qb->expr()->isNotNull('manager_uid'))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($this->asStoredTimestamp($before), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($this->asStoredTimestamp($after), IQueryBuilder::PARAM_STR)));
		return $this->findEntities($qb);
	}

	/**
	 * Requests for a set of employees overlapping a range, restricted to statuses.
	 * Used for coverage / who's-off calendar.
	 *
	 * @param string[] $employeeUids
	 * @param string[] $statuses
	 * @return LeaveRequest[]
	 */
	public function findForEmployeesInRange(array $employeeUids, string $start, string $end, array $statuses): array {
		if ($employeeUids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('employee_uid', $qb->createNamedParameter($employeeUids, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter($statuses, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($end)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($start)));
		return $this->findEntities($qb);
	}

	/**
	 * Approved requests without calendar events that end on $today ('Y-m-d') or
	 * later, with an id above $afterId, oldest first (§10).
	 *
	 * @return LeaveRequest[]
	 */
	public function findApprovedWithoutCalendarEvent(int $afterId, int $limit, string $today): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(LeaveRequest::STATUS_APPROVED)))
			->andWhere($qb->expr()->isNull('calendar_event_uri'))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($today)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Store the calendar reference of a request if it is still approved. Returns
	 * whether it was stored.
	 *
	 * The calendar sync job writes events for a list read moments earlier; a
	 * request cancelled in between must not keep a reference to an event its
	 * cancellation never saw. See {@see markEscalated()}.
	 */
	public function setCalendarEventUriIfApproved(int $id, string $uri): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('calendar_event_uri', $qb->createNamedParameter($uri))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(LeaveRequest::STATUS_APPROVED)));
		return $qb->executeStatement() === 1;
	}

	/**
	 * All requests for an employee (any status), used for balance computation.
	 * With $year, only requests *starting* in that year — the year balances
	 * attribute them to — so one person's balance never loads their whole
	 * multi-year history.
	 *
	 * @return LeaveRequest[]
	 */
	public function findAllForEmployee(string $employeeUid, ?int $year = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($employeeUid)))
			->orderBy('start_date', 'DESC');
		if ($year !== null) {
			$qb->andWhere($qb->expr()->gte('start_date', $qb->createNamedParameter(sprintf('%04d-01-01', $year))))
				->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter(sprintf('%04d-12-31', $year))));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Working days per (employee, type, status) for requests *starting* in the
	 * given year, aggregated in SQL.
	 *
	 * The batch balance path used to load every request ever written for every
	 * employee and sum in PHP, so the HR balances report scaled with the total
	 * history rather than the headcount. This returns at most a handful of rows
	 * per employee and type, however many years pile up. Only the statuses that
	 * affect a balance (§4.2) are aggregated.
	 *
	 * @param string[] $employeeUids
	 * @return list<array{employee_uid:string,type_id:int,status:string,days:float}>
	 */
	public function aggregateWorkingDaysForYear(array $employeeUids, int $year): array {
		$rows = [];
		foreach (array_chunk($employeeUids, self::IN_CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('employee_uid', 'type_id', 'status')
				->selectAlias($qb->func()->sum('working_days'), 'days')
				->from($this->getTableName())
				->where($qb->expr()->in('employee_uid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
					LeaveRequest::ACTIVE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($qb->expr()->gte('start_date', $qb->createNamedParameter(sprintf('%04d-01-01', $year))))
				->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter(sprintf('%04d-12-31', $year))))
				->groupBy('employee_uid', 'type_id', 'status');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$rows[] = [
					'employee_uid' => (string)$row['employee_uid'],
					'type_id' => (int)$row['type_id'],
					'status' => (string)$row['status'],
					'days' => (float)$row['days'],
				];
			}
			$result->closeCursor();
		}
		return $rows;
	}

	/**
	 * Pending edits of approved leave (§5.3) starting in the given year — the
	 * few rows the SQL aggregation cannot net out on its own, fetched separately
	 * so the netting rule of {@see \OCA\Absence\Service\BalanceService} can
	 * subtract the days their originals already count.
	 *
	 * @param string[] $employeeUids
	 * @return LeaveRequest[]
	 */
	public function findPendingSupersedingForYear(array $employeeUids, int $year): array {
		$requests = [];
		foreach (array_chunk($employeeUids, self::IN_CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('employee_uid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($qb->expr()->isNotNull('supersedes_id'))
				->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
					LeaveRequest::PENDING_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($qb->expr()->gte('start_date', $qb->createNamedParameter(sprintf('%04d-01-01', $year))))
				->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter(sprintf('%04d-12-31', $year))));
			array_push($requests, ...$this->findEntities($qb));
		}
		return $requests;
	}

	/**
	 * @param int[] $ids
	 * @return array<int,LeaveRequest> keyed by id
	 */
	public function findByIds(array $ids): array {
		$byId = [];
		foreach (array_chunk($ids, self::IN_CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->findEntities($qb) as $request) {
				$byId[(int)$request->getId()] = $request;
			}
		}
		return $byId;
	}

	/**
	 * All requests overlapping a range across the whole instance (HR reporting).
	 *
	 * @return LeaveRequest[]
	 */
	public function findAllInRange(string $start, string $end): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->lte('start_date', $qb->createNamedParameter($end)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($start)))
			->orderBy('start_date', 'ASC');
		return $this->findEntities($qb);
	}
}
