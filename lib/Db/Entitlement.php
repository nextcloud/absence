<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getEmployeeUid()
 * @method void setEmployeeUid(string $employeeUid)
 * @method int getYear()
 * @method void setYear(int $year)
 * @method int getTypeId()
 * @method void setTypeId(int $typeId)
 * @method float getBaseDays()
 * @method void setBaseDays(float $baseDays)
 * @method float getCarryOverDays()
 * @method void setCarryOverDays(float $carryOverDays)
 * @method float getManualAdjustment()
 * @method void setManualAdjustment(float $manualAdjustment)
 * @method string|null getAdjustmentNote()
 * @method void setAdjustmentNote(?string $adjustmentNote)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class Entitlement extends Entity implements \JsonSerializable {
	protected string $employeeUid = '';
	protected int $year = 0;
	protected int $typeId = 0;
	protected float $baseDays = 0.0;
	protected float $carryOverDays = 0.0;
	protected float $manualAdjustment = 0.0;
	protected ?string $adjustmentNote = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('year', 'integer');
		$this->addType('typeId', 'integer');
		$this->addType('baseDays', 'float');
		$this->addType('carryOverDays', 'float');
		$this->addType('manualAdjustment', 'float');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	/** Total entitlement for the year (see spec §3.4). */
	public function getEntitlement(): float {
		return $this->baseDays + $this->carryOverDays + $this->manualAdjustment;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'employeeUid' => $this->employeeUid,
			'year' => $this->year,
			'typeId' => $this->typeId,
			'baseDays' => $this->baseDays,
			'carryOverDays' => $this->carryOverDays,
			'manualAdjustment' => $this->manualAdjustment,
			'adjustmentNote' => $this->adjustmentNote,
			'entitlement' => $this->getEntitlement(),
		];
	}
}
