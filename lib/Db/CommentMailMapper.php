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
 * @extends QBMapper<CommentMail>
 */
class CommentMailMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'absence_comment_mails', CommentMail::class);
	}

	/**
	 * @param list<string> $recipientUids
	 */
	public function queue(RequestComment $comment, array $recipientUids): void {
		foreach ($recipientUids as $uid) {
			$row = new CommentMail();
			$row->setCommentId($comment->getId());
			$row->setRequestId($comment->getRequestId());
			$row->setRecipientUid($uid);
			$this->insert($row);
		}
	}

	/**
	 * @return list<int> the requests with comment mail waiting
	 */
	public function findRequestIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('request_id')->from($this->getTableName());
		$result = $qb->executeQuery();
		$ids = array_map(intval(...), $result->fetchFirstColumn());
		$result->closeCursor();
		return $ids;
	}

	/**
	 * @return list<CommentMail>
	 */
	public function findForRequest(int $requestId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $requestIds
	 */
	public function deleteForRequests(array $requestIds): void {
		foreach (array_chunk($requestIds, 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('request_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	/**
	 * @param list<int> $ids
	 */
	public function deleteIds(array $ids): void {
		foreach (array_chunk($ids, 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}
}
