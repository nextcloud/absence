<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<HolidayChange>
 */
class HolidayChangeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'absence_holiday_history', HolidayChange::class);
	}

	/**
	 * @return list<HolidayChange> newest first
	 */
	public function findRecent(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}
}
