<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ContractPeriod>
 */
class ContractPeriodMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'absence_contract_periods', ContractPeriod::class);
	}

	/**
	 * @return list<ContractPeriod> oldest first
	 */
	public function findForEmployee(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($uid)))
			->orderBy('valid_from', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @param list<string> $uids
	 * @return list<ContractPeriod> oldest first
	 */
	public function findForEmployees(array $uids): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('employee_uid', $qb->createNamedParameter($uids, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('valid_from', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteForEmployee(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($uid)))
			->executeStatement();
	}
}
