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
 * @extends QBMapper<RequestComment>
 */
class RequestCommentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'absence_comments', RequestComment::class);
	}

	/**
	 * @return RequestComment[]
	 */
	public function findForRequest(int $requestId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $requestIds
	 */
	public function deleteForRequests(array $requestIds): void {
		// Oracle accepts at most 1000 values in one IN() list.
		foreach (array_chunk($requestIds, 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('request_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}
}
