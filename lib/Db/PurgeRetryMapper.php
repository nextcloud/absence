<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\IDBConnection;

/**
 * The deleted accounts whose purge (§17) failed and is still to be retried,
 * one row per uid.
 */
class PurgeRetryMapper {
	private const TABLE = 'absence_purge_retry';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function add(string $uid): void {
		$this->db->insertIgnoreConflict(self::TABLE, ['uid' => $uid]);
	}

	/**
	 * @return list<string>
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid')->from(self::TABLE)->orderBy('uid');
		$result = $qb->executeQuery();
		$uids = array_map(strval(...), $result->fetchFirstColumn());
		$result->closeCursor();
		return $uids;
	}

	public function remove(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}
}
