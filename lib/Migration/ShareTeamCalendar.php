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
 * Shares the team calendar with the employees and HR groups (§10), for
 * calendars created before it was shared with anyone.
 */
class ShareTeamCalendar implements IRepairStep {
	public function __construct(
		private CalendarService $calendar,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Share the Absence team calendar';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$this->calendar->shareTeamCalendar();
	}
}
