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
 * @extends QBMapper<EntitlementEvent>
 */
class EntitlementEventMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'absence_entitlement_events', EntitlementEvent::class);
	}

	/**
	 * The full chronological history for one entitlement, oldest first.
	 *
	 * The id tiebreaker matters here: several figures can change in one save and
	 * therefore share a timestamp to the second, and without it the order they are
	 * shown in could differ between loads.
	 *
	 * @return EntitlementEvent[]
	 */
	public function findForEntitlement(int $entitlementId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('entitlement_id', $qb->createNamedParameter($entitlementId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Every recorded change for one employee, newest first — the whole story of
	 * their allowance across years and leave types.
	 *
	 * @return EntitlementEvent[]
	 */
	public function findForEmployee(string $employeeUid, ?int $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($employeeUid)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC');
		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		return $this->findEntities($qb);
	}

	/** Used by the GDPR purge when an account is deleted (§17). */
	public function deleteForEmployee(string $employeeUid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($employeeUid)));
		$qb->executeStatement();
	}
}
