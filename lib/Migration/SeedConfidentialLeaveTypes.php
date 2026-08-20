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
 * Seeds the confidential leave types (§5.7): maternity leave, medical work
 * prohibition and doctor's note. All three are recorded by HR and visible only
 * to HR — everyone else sees a neutral absence.
 *
 * Unlike {@see SeedLeaveTypes} (install-time, only into an empty table), this
 * runs on updates too and is idempotent per key, so existing installations get
 * the new categories without touching anything HR has customised. HR can
 * rename or disable them like any other type.
 */
class SeedConfidentialLeaveTypes implements IRepairStep {
	/** @var array<int,array{key:string,label:string,color:string,icon:string}> */
	private const TYPES = [
		['key' => 'maternity', 'label' => 'Maternity leave', 'color' => '#8e44ad', 'icon' => '🤱'],
		['key' => 'work_prohibition', 'label' => 'Medical work prohibition', 'color' => '#a04545', 'icon' => '⚕️'],
		['key' => 'doctors_note', 'label' => "Doctor's note", 'color' => '#2e7da6', 'icon' => '🩺'],
		['key' => 'parental', 'label' => 'Parental leave', 'color' => '#0e7d6e', 'icon' => '🍼'],
		['key' => 'child_sick', 'label' => 'Child sick leave', 'color' => '#c98a1e', 'icon' => '🧒'],
	];

	public function __construct(
		private LeaveTypeMapper $mapper,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Seed confidential (HR-only) absence leave types';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$existing = [];
		$maxOrder = -1;
		foreach ($this->mapper->findAll() as $type) {
			$existing[$type->getKey()] = true;
			$maxOrder = max($maxOrder, $type->getSortOrder());
		}
		foreach (self::TYPES as $def) {
			if (isset($existing[$def['key']])) {
				continue;
			}
			$type = new LeaveType();
			$type->setKey($def['key']);
			$type->setLabel($def['label']);
			$type->setColor($def['color']);
			$type->setIcon($def['icon']);
			$type->setCountsAgainstBalance(false);
			$type->setRequiresApproval(false);
			$type->setRequiresNote(false);
			$type->setRequiresReplacement(false);
			$type->setEmployeeRequestable(false);
			$type->setHrOnly(true);
			$type->setEnabled(true);
			$type->setSortOrder(++$maxOrder);
			$this->mapper->insert($type);
			$output->info('Seeded confidential leave type: ' . $def['label']);
		}
	}
}
