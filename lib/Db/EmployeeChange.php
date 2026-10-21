<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One change to someone's contract data or region code, with its author (§6.4).
 *
 * @method string getEmployeeUid()
 * @method void setEmployeeUid(string $uid)
 * @method ?string getActorUid()
 * @method void setActorUid(?string $uid)
 * @method string getField()
 * @method void setField(string $field)
 * @method string getDetail()
 * @method void setDetail(string $detail)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class EmployeeChange extends Entity {
	public const CONTRACT = 'contract';
	public const REGION = 'region';

	protected string $employeeUid = '';
	protected ?string $actorUid = null;
	protected string $field = '';
	protected string $detail = '{}';
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('employeeUid', 'string');
		$this->addType('actorUid', 'string');
		$this->addType('field', 'string');
		$this->addType('detail', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
