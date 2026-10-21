<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\Entity;

/**
 * When someone joined and, once known, when they leave (§6.4).
 *
 * @method string getEmployeeUid()
 * @method void setEmployeeUid(string $uid)
 * @method ?string getStartDate()
 * @method void setStartDate(?string $date)
 * @method ?string getEndDate()
 * @method void setEndDate(?string $date)
 */
class Employment extends Entity {
	protected string $employeeUid = '';
	protected ?string $startDate = null;
	protected ?string $endDate = null;

	public function __construct() {
		$this->addType('employeeUid', 'string');
		$this->addType('startDate', 'string');
		$this->addType('endDate', 'string');
	}
}
