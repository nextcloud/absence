<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<LeaveType>
 *
 * Leave types are a handful of rows read from all over the app — permission
 * checks, history rendering, calendar titles, serialization — so the mapper
 * memoises the full table per request. One query per request instead of one
 * per call site; every write drops the memo.
 */
class LeaveTypeMapper extends QBMapper {
	/** @var LeaveType[]|null all rows in display order, memoised per request */
	private ?array $allCache = null;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'absence_leave_types', LeaveType::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): LeaveType {
		foreach ($this->findAll() as $type) {
			if ($type->getId() === $id) {
				return $type;
			}
		}
		throw new DoesNotExistException('Leave type ' . $id . ' does not exist');
	}

	/**
	 * @return LeaveType[]
	 */
	public function findAll(bool $onlyEnabled = false): array {
		if ($this->allCache === null) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->getTableName());
			$qb->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC');
			$this->allCache = $this->findEntities($qb);
		}
		if (!$onlyEnabled) {
			return $this->allCache;
		}
		return array_values(array_filter(
			$this->allCache,
			static fn (LeaveType $type): bool => $type->getEnabled(),
		));
	}

	#[\Override]
	public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity {
		$this->allCache = null;
		return parent::insert($entity);
	}

	#[\Override]
	public function update(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity {
		$this->allCache = null;
		return parent::update($entity);
	}

	#[\Override]
	public function delete(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity {
		$this->allCache = null;
		return parent::delete($entity);
	}

	/**
	 * Ids of the confidential (HR-only, §5.7) leave types — served from the
	 * per-request memo, so callers can consult it per row for free.
	 *
	 * @return int[]
	 */
	public function hrOnlyTypeIds(): array {
		$ids = [];
		foreach ($this->findAll() as $type) {
			if ($type->getHrOnly()) {
				$ids[] = (int)$type->getId();
			}
		}
		return $ids;
	}

	public function countAll(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName());
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}
}
