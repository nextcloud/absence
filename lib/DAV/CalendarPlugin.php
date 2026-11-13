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
 * Puts the read-only personal Absence calendar into each employee's calendar
 * home (§10).
 *
 * It is offered for any user principal, not only the one logged in: the
 * calendar's ACL limits access to its owner and their delegates, and free/busy
 * lookups by colleagues need to see it.
 */
class CalendarPlugin implements ICalendarProvider {
	public const CALENDAR_URI = 'absence';
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
		$calendar = $this->calendarFor($principalUri);
		return $calendar === null ? [] : [$calendar];
	}

	#[\Override]
	public function hasCalendarInCalendarHome(string $principalUri, string $calendarUri): bool {
		return $calendarUri === self::CALENDAR_URI && $this->calendarFor($principalUri) !== null;
	}

	#[\Override]
	public function getCalendarInCalendarHome(string $principalUri, string $calendarUri): ?ExternalCalendar {
		return $calendarUri === self::CALENDAR_URI ? $this->calendarFor($principalUri) : null;
	}

	private function calendarFor(string $principalUri): ?PersonalCalendar {
		if (!str_starts_with($principalUri, self::USER_PRINCIPAL_PREFIX)) {
			return null;
		}
		$uid = substr($principalUri, strlen(self::USER_PRINCIPAL_PREFIX));
		if ($uid === '' || !$this->calendar->hasPersonalCalendar($uid)) {
			return null;
		}
		return new PersonalCalendar($principalUri, $uid, $this->calendar);
	}
}
