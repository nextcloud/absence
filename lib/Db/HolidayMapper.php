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
 * @extends QBMapper<Holiday>
 */
class HolidayMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'absence_holidays', Holiday::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Holiday {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Holidays dated in the range, plus every recurring one (they repeat from their
	 * first year on, so the caller expands them).
	 *
	 * @param list<string>|null $regions null for all regions
	 * @return list<Holiday>
	 */
	public function findInRange(string $from, string $to, ?array $regions = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->andX(
					$qb->expr()->gte('date', $qb->createNamedParameter($from)),
					$qb->expr()->lte('date', $qb->createNamedParameter($to)),
				),
				$qb->expr()->eq('recurring', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
			))
			->orderBy('date')->addOrderBy('region');
		if ($regions !== null) {
			$qb->andWhere($qb->expr()->in('region', $qb->createNamedParameter($regions, IQueryBuilder::PARAM_STR_ARRAY)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * @return list<Holiday>
	 */
	public function findByRegionAndDate(string $region, string $date): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('region', $qb->createNamedParameter($region)))
			->andWhere($qb->expr()->eq('date', $qb->createNamedParameter($date)));
		return $this->findEntities($qb);
	}
}
