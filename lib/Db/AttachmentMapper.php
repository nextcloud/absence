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
 * @extends QBMapper<Attachment>
 */
class AttachmentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'absence_attachments', Attachment::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Attachment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return Attachment[]
	 */
	public function findForRequest(int $requestId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function countForRequest(int $requestId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Bytes of the attachments on all requests of one employee, whoever
	 * uploaded them.
	 */
	public function sumSizeForEmployee(string $employeeUid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->sum('a.size'), 'bytes')
			->from($this->getTableName(), 'a')
			->innerJoin('a', 'absence_requests', 'r', $qb->expr()->eq('a.request_id', 'r.id'))
			->where($qb->expr()->eq('r.employee_uid', $qb->createNamedParameter($employeeUid)));
		$result = $qb->executeQuery();
		$bytes = (int)$result->fetchOne();
		$result->closeCursor();
		return $bytes;
	}

	/**
	 * Attachments whose stored file is still plaintext, by id from after $afterId.
	 *
	 * @return list<Attachment>
	 */
	public function findUnencrypted(int $afterId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('encrypted', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Records that an attachment's file is now stored encrypted; false when the
	 * row is gone.
	 */
	public function markEncrypted(int $id): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('encrypted', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement() > 0;
	}

	/**
	 * All attachment rows on a set of requests — the GDPR purge path.
	 *
	 * @param int[] $requestIds
	 * @return Attachment[]
	 */
	public function findForRequests(array $requestIds): array {
		$attachments = [];
		// Oracle accepts at most 1000 values in one IN() list.
		foreach (array_chunk($requestIds, 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('request_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			array_push($attachments, ...$this->findEntities($qb));
		}
		return $attachments;
	}
}
