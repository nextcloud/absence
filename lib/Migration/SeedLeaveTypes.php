<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Seeds the default leave types on install (spec §3.2). Idempotent: only seeds
 * when the table is empty so it never clobbers HR customisations.
 */
class SeedLeaveTypes implements IRepairStep {
	/** @var array<int,array{key:string,label:string,color:string,icon:string,balance:bool,approval:bool,note:bool,requestable:bool,replacement:bool}> */
	private const DEFAULTS = [
		['key' => 'annual', 'label' => 'Annual leave', 'color' => '#2d7d46', 'icon' => '🌴', 'balance' => true, 'approval' => true, 'note' => false, 'requestable' => true, 'replacement' => true],
		// Sick leave is recorded by HR, not self-requested (no approval workflow, no replacement).
		['key' => 'sick', 'label' => 'Sick leave', 'color' => '#c9791e', 'icon' => '🤒', 'balance' => false, 'approval' => false, 'note' => false, 'requestable' => false, 'replacement' => false],
		['key' => 'unpaid', 'label' => 'Unpaid leave', 'color' => '#6c6c6c', 'icon' => '💸', 'balance' => false, 'approval' => true, 'note' => false, 'requestable' => true, 'replacement' => true],
		['key' => 'special', 'label' => 'Special leave', 'color' => '#4271a6', 'icon' => '🕊️', 'balance' => false, 'approval' => true, 'note' => false, 'requestable' => true, 'replacement' => true],
	];

	public function __construct(
		private LeaveTypeMapper $mapper,
	) {
	}

	public function getName(): string {
		return 'Seed default absence leave types';
	}

	public function run(IOutput $output): void {
		if ($this->mapper->countAll() > 0) {
			return;
		}
		$order = 0;
		foreach (self::DEFAULTS as $def) {
			$type = new LeaveType();
			$type->setKey($def['key']);
			$type->setLabel($def['label']);
			$type->setColor($def['color']);
			$type->setIcon($def['icon']);
			$type->setCountsAgainstBalance($def['balance']);
			$type->setRequiresApproval($def['approval']);
			$type->setRequiresNote($def['note']);
			$type->setRequiresReplacement($def['replacement']);
			$type->setEmployeeRequestable($def['requestable']);
			$type->setEnabled(true);
			$type->setSortOrder($order++);
			$this->mapper->insert($type);
		}
		$output->info('Seeded ' . count(self::DEFAULTS) . ' default leave types.');
	}
}
