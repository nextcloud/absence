<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\DAV;

use OCA\Absence\Service\CalendarService;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;

/**
 * The "Team absences" calendar of an employee or HR member: every employee's
 * leave in force around today (§10). Only its owner reads it: a delegate's
 * access follows the owner, not the delegate's own role. It is transparent, so
 * colleagues' leave never makes the owner busy.
 */
class TeamCalendar extends LeaveCalendar {
	private const TRANSP = '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp';

	public function __construct(
		string $principalUri,
		private string $uid,
		private CalendarService $calendar,
	) {
		parent::__construct(CalendarPlugin::TEAM_CALENDAR_URI, $principalUri);
	}

	#[\Override]
	public function readAcl(): array {
		return [[
			'privilege' => '{DAV:}read',
			'principal' => $this->principalUri,
			'protected' => true,
		]];
	}

	#[\Override]
	public function getProperties($properties) {
		return parent::getProperties($properties) + [
			self::TRANSP => new ScheduleCalendarTransp(ScheduleCalendarTransp::TRANSPARENT),
		];
	}

	#[\Override]
	protected function displayName(): string {
		return $this->calendar->teamCalendarName($this->uid);
	}

	#[\Override]
	protected function loadEvents(): array {
		return $this->calendar->teamEvents($this->uid);
	}
}
