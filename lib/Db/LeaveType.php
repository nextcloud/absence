<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getKey()
 * @method void setKey(string $key)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string getColor()
 * @method void setColor(string $color)
 * @method string getIcon()
 * @method void setIcon(string $icon)
 * @method bool getCountsAgainstBalance()
 * @method void setCountsAgainstBalance(bool $countsAgainstBalance)
 * @method bool getRequiresApproval()
 * @method void setRequiresApproval(bool $requiresApproval)
 * @method bool getRequiresNote()
 * @method void setRequiresNote(bool $requiresNote)
 * @method bool getRequiresReplacement()
 * @method void setRequiresReplacement(bool $requiresReplacement)
 * @method bool getEmployeeRequestable()
 * @method void setEmployeeRequestable(bool $employeeRequestable)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 */
class LeaveType extends Entity implements \JsonSerializable {
	protected string $key = '';
	protected string $label = '';
	protected string $color = '#0082c9';
	protected string $icon = '🌴';
	protected bool $countsAgainstBalance = true;
	protected bool $requiresApproval = true;
	protected bool $requiresNote = false;
	/** When true, the employee must nominate a replacement colleague (§5.1). */
	protected bool $requiresReplacement = false;
	/** When false, employees cannot self-request this type; HR records it (e.g. sick leave). */
	protected bool $employeeRequestable = true;
	protected bool $enabled = true;
	protected int $sortOrder = 0;

	public function __construct() {
		$this->addType('countsAgainstBalance', 'boolean');
		$this->addType('requiresApproval', 'boolean');
		$this->addType('requiresNote', 'boolean');
		$this->addType('requiresReplacement', 'boolean');
		$this->addType('employeeRequestable', 'boolean');
		$this->addType('enabled', 'boolean');
		$this->addType('sortOrder', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'key' => $this->key,
			'label' => $this->label,
			'color' => $this->color,
			'icon' => $this->icon,
			'countsAgainstBalance' => $this->countsAgainstBalance,
			'requiresApproval' => $this->requiresApproval,
			'requiresNote' => $this->requiresNote,
			'requiresReplacement' => $this->requiresReplacement,
			'employeeRequestable' => $this->employeeRequestable,
			'enabled' => $this->enabled,
			'sortOrder' => $this->sortOrder,
		];
	}
}
