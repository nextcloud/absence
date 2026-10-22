<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The working weekdays and contract percentage someone has from a date on, until
 * the next period starts (§6.4).
 *
 * @method string getEmployeeUid()
 * @method void setEmployeeUid(string $uid)
 * @method string getValidFrom()
 * @method void setValidFrom(string $date)
 * @method string getWeekdays()
 * @method void setWeekdays(string $weekdays)
 * @method ?int getPercent()
 * @method void setPercent(?int $percent)
 */
class ContractPeriod extends Entity {
	protected string $employeeUid = '';
	protected string $validFrom = '';
	protected string $weekdays = '';
	protected ?int $percent = null;

	public function __construct() {
		$this->addType('employeeUid', 'string');
		$this->addType('validFrom', 'string');
		$this->addType('weekdays', 'string');
		$this->addType('percent', 'integer');
	}

	/**
	 * @return list<int> ISO weekdays, Mon=1
	 */
	public function getWeekdayList(): array {
		return array_map('intval', explode(',', $this->weekdays));
	}
}
