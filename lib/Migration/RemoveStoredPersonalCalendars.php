<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use OCA\Absence\Service\CalendarService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Removes the personal calendar events stored in each employee's own calendar,
 * now that the read-only personal calendar is built from the leave itself (§10).
 */
class RemoveStoredPersonalCalendars implements IRepairStep {
	public function __construct(
		private CalendarService $calendar,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Remove the stored personal Absence calendars';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$this->calendar->removeStoredPersonalEvents();
	}
}
