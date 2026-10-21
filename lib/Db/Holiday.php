<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A public holiday HR entered for a region code (§7.2).
 *
 * @method string getRegion()
 * @method void setRegion(string $region)
 * @method string getDate()
 * @method void setDate(string $date)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method bool getRecurring()
 * @method void setRecurring(bool $recurring)
 * @method ?int getYear()
 * @method void setYear(?int $year)
 */
class Holiday extends Entity implements \JsonSerializable {
	protected string $region = '';
	protected string $date = '';
	protected string $label = '';
	protected bool $recurring = false;
	protected ?int $year = null;

	public function __construct() {
		$this->addType('region', 'string');
		$this->addType('date', 'string');
		$this->addType('label', 'string');
		$this->addType('recurring', 'boolean');
		$this->addType('year', 'integer');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'region' => $this->region,
			'date' => $this->date,
			'label' => $this->label,
			'recurring' => $this->recurring,
		];
	}
}
