<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\DAV;

use OCA\Absence\Service\CalendarService;
use OCA\Absence\Service\ConfigService;
use OCA\DAV\CalDAV\Integration\ExternalCalendar;
use OCA\DAV\CalDAV\Integration\ICalendarProvider;

/**
 * Puts the read-only Absence calendars into users' calendar homes (§10): the
 * personal calendar of each employee with leave in force, and the team
 * calendar of each employee and HR member.
 *
 * They are offered for any user principal, not only the one logged in: the
 * calendars' ACL limits access, and free/busy lookups by colleagues need to see
 * the personal calendar.
 */
class CalendarPlugin implements ICalendarProvider {
	public const CALENDAR_URI = 'absence';
	public const TEAM_CALENDAR_URI = 'team';
	private const USER_PRINCIPAL_PREFIX = 'principals/users/';

	public function __construct(
		private CalendarService $calendar,
	) {
	}

	#[\Override]
	public function getAppId(): string {
		return ConfigService::APP_ID;
	}

	#[\Override]
	public function fetchAllForCalendarHome(string $principalUri): array {
		return array_values(array_filter([
			$this->calendarFor($principalUri, self::CALENDAR_URI),
			$this->calendarFor($principalUri, self::TEAM_CALENDAR_URI),
		]));
	}

	#[\Override]
	public function hasCalendarInCalendarHome(string $principalUri, string $calendarUri): bool {
		return $this->calendarFor($principalUri, $calendarUri) !== null;
	}

	#[\Override]
	public function getCalendarInCalendarHome(string $principalUri, string $calendarUri): ?ExternalCalendar {
		return $this->calendarFor($principalUri, $calendarUri);
	}

	private function calendarFor(string $principalUri, string $calendarUri): ?LeaveCalendar {
		if (!str_starts_with($principalUri, self::USER_PRINCIPAL_PREFIX)) {
			return null;
		}
		$uid = substr($principalUri, strlen(self::USER_PRINCIPAL_PREFIX));
		if ($uid === '') {
			return null;
		}
		return match ($calendarUri) {
			self::CALENDAR_URI => $this->calendar->hasPersonalCalendar($uid) ? new PersonalCalendar($principalUri, $uid, $this->calendar) : null,
			self::TEAM_CALENDAR_URI => $this->calendar->hasTeamCalendar($uid) ? new TeamCalendar($principalUri, $uid, $this->calendar) : null,
			default => null,
		};
	}
}
