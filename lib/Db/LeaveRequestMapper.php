<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<LeaveRequest>
 */
class LeaveRequestMapper extends QBMapper {
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
	 * Generic filtered query used by the API list endpoint.
	 *
	 * @param array{status?:string,typeId?:int,from?:string,to?:string,employeeUid?:string,managerUid?:string} $filters
	 * @return LeaveRequest[]
	 */
	public function findFiltered(array $filters, ?int $limit = null, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());

		if (!empty($filters['employeeUid'])) {
			$qb->andWhere($qb->expr()->eq('employee_uid', $qb->createNamedParameter($filters['employeeUid'])));
		}
		if (!empty($filters['managerUid'])) {
			$qb->andWhere($qb->expr()->eq('manager_uid', $qb->createNamedParameter($filters['managerUid'])));
		}
		if (!empty($filters['status'])) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($filters['status'])));
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

		$qb->orderBy('start_date', 'DESC');
		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}
		return $this->findEntities($qb);
	}

	/**
	 * Requests awaiting a decision from a given manager.
	 *
	 * @return LeaveRequest[]
	 */
	public function findPendingForManager(string $managerUid): array {
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
	 * Pending requests created before the given cut-off (for escalation/reminders).
	 *
	 * @return LeaveRequest[]
	 */
	public function findPendingOlderThan(\DateTimeInterface $cutoff): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(LeaveRequest::STATUS_PENDING)))
			->andWhere($qb->expr()->isNotNull('manager_uid'))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter(
				$cutoff->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)));
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
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($before->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($after->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)));
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
	 * All requests for an employee (any status), used for balance computation.
	 *
	 * @return LeaveRequest[]
	 */
	public function findAllForEmployee(string $employeeUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($employeeUid)))
			->orderBy('start_date', 'DESC');
		return $this->findEntities($qb);
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
