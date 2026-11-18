<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\DAV;

use OCA\Absence\Service\CalendarService;

/**
 * An employee's personal Absence calendar: their leave in force (§10). Its
 * owner and their calendar delegates read it.
 */
class PersonalCalendar extends LeaveCalendar {
	public function __construct(
		string $principalUri,
		private string $uid,
		private CalendarService $calendar,
	) {
		parent::__construct(CalendarPlugin::CALENDAR_URI, $principalUri);
	}

	/**
	 * Read access for the owner and their calendar delegates.
	 */
	#[\Override]
	public function readAcl(): array {
		return array_map(static fn (string $principal): array => [
			'privilege' => '{DAV:}read',
			'principal' => $principal,
			'protected' => true,
		], [
			$this->principalUri,
			$this->principalUri . '/calendar-proxy-read',
			$this->principalUri . '/calendar-proxy-write',
		]);
	}

	#[\Override]
	protected function displayName(): string {
		return $this->calendar->personalCalendarName($this->uid);
	}

	#[\Override]
	protected function loadEvents(): array {
		return $this->calendar->personalEvents($this->uid);
	}
}
