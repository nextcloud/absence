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
 * @extends QBMapper<Employment>
 */
class EmploymentMapper extends QBMapper {
	/** The tables that hold someone's data besides their employment */
	private const DATA_TABLES = ['absence_requests', 'absence_entitlements', 'absence_ent_events', 'absence_periods', 'absence_emp_history'];

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'absence_employments', Employment::class);
	}

	/**
	 * @param list<string> $uids
	 * @return list<Employment>
	 */
	public function findForEmployees(array $uids): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('employee_uid', $qb->createNamedParameter($uids, IQueryBuilder::PARAM_STR_ARRAY)));
		return $this->findEntities($qb);
	}

	/**
	 * The employments whose end date lies before a day and whose person still
	 * has leave, entitlements, contract periods or history, by id, the ones after
	 * $afterId only. Never one without an end date.
	 *
	 * @return list<Employment>
	 */
	public function findEndedBeforeWithData(string $date, int $afterId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$withData = [];
		foreach (self::DATA_TABLES as $table) {
			$uids = $this->db->getQueryBuilder();
			$uids->select('employee_uid')->from($table);
			$withData[] = $qb->expr()->in('employee_uid', $qb->createFunction($uids->getSQL()));
		}
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->lt('end_date', $qb->createNamedParameter($date)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(...$withData))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function findForEmployee(string $uid): ?Employment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($uid)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
