<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One recorded change to one figure on an entitlement (§6.1).
 *
 * @method string getEmployeeUid()
 * @method void setEmployeeUid(string $employeeUid)
 * @method int getEntitlementId()
 * @method void setEntitlementId(int $entitlementId)
 * @method string getActorUid()
 * @method void setActorUid(string $actorUid)
 * @method string getField()
 * @method void setField(string $field)
 * @method float getOldValue()
 * @method void setOldValue(float $oldValue)
 * @method float getNewValue()
 * @method void setNewValue(float $newValue)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class EntitlementEvent extends Entity implements \JsonSerializable {
	/** The figures an entitlement is made of, and which this records changes to. */
	public const FIELD_BASE_DAYS = 'base_days';
	public const FIELD_CARRY_OVER_DAYS = 'carry_over_days';
	public const FIELD_MANUAL_ADJUSTMENT = 'manual_adjustment';

	protected int $entitlementId = 0;
	protected string $employeeUid = '';
	protected string $actorUid = '';
	protected string $field = '';
	protected float $oldValue = 0.0;
	protected float $newValue = 0.0;
	protected ?string $note = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('entitlementId', 'integer');
		$this->addType('oldValue', 'float');
		$this->addType('newValue', 'float');
		$this->addType('createdAt', 'datetime');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'entitlementId' => $this->entitlementId,
			'employeeUid' => $this->employeeUid,
			'actorUid' => $this->actorUid,
			'field' => $this->field,
			'oldValue' => $this->oldValue,
			'newValue' => $this->newValue,
			// The client renders "+2" rather than re-deriving it from the two values,
			// so the sign it shows and the one the audit log records cannot drift.
			'delta' => round($this->newValue - $this->oldValue, 1),
			'note' => $this->note,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
