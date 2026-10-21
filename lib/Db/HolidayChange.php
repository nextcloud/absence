<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One change to the public holiday list (§7.2).
 *
 * @method string getAction()
 * @method void setAction(string $action)
 * @method ?string getActorUid()
 * @method void setActorUid(?string $actorUid)
 * @method string getDetail()
 * @method void setDetail(string $detail)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class HolidayChange extends Entity {
	public const ADDED = 'added';
	public const CHANGED = 'changed';
	public const DELETED = 'deleted';
	public const IMPORTED = 'imported';
	public const EVERYONE_EDITS = 'everyone_edits';
	public const HR_EDITS = 'hr_edits';

	protected string $action = '';
	protected ?string $actorUid = null;
	protected string $detail = '{}';
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('action', 'string');
		$this->addType('actorUid', 'string');
		$this->addType('detail', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
