<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\ConfigLexicon;
use OCA\DAV\CalDAV\Schedule\Plugin;
use OCA\DAV\Db\PropertyMapper;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

/**
 * Works out sensible defaults for the request form's "Working days" prefill from
 * data the user already maintains in Nextcloud, so they need not re-enter it:
 *
 *  - working weekdays ← their contract (§6.4), or their Availability
 *    (/settings/user/availability) while HR has not entered one
 *
 * The weekdays are only a suggestion: the request field stays editable. The
 * holiday region code is never guessed: people set it themselves (§7.2).
 */
class PersonalDefaultsService {
	/** Availability RRULE BYDAY tokens → ISO weekday (Mon=1..Sun=7). */
	private const BYDAY = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

	public function __construct(
		private ConfigService $appConfig,
		private EmployeeService $employees,
		private ClockService $clock,
		private PropertyMapper $propertyMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * What the request-form prefill needs: the weekdays someone works today
	 * (contract ?: Availability ?: Mon-Fri) and their region code.
	 *
	 * @return array{workWeekdays: string, holidayCountry: string, holidayRegion: string}
	 */
	public function resolve(string $uid): array {
		$stored = $this->appConfig->getPersonalConfig($uid);
		$weekdays = $this->employees->weekdaysOn($uid, $this->clock->userToday()) ?? $this->detectWorkingWeekdays($uid);

		return [
			// Always a CSV string
			'workWeekdays' => $weekdays !== null ? implode(',', $weekdays) : ConfigService::DEFAULT_WORK_WEEKDAYS,
			'holidayCountry' => $stored[ConfigLexicon::KEY_HOLIDAY_COUNTRY],
			'holidayRegion' => $stored[ConfigLexicon::KEY_HOLIDAY_REGION],
		];
	}

	/**
	 * ISO weekdays (1-7) the user works, read from their Availability, or null when
	 * they have not configured Availability yet.
	 *
	 * @return int[]|null
	 */
	public function detectWorkingWeekdays(string $uid): ?array {
		$value = $this->readAvailability($uid);
		if ($value === null) {
			return null;
		}
		try {
			$vcalendar = Reader::read($value);
			$days = [];
			// Mirror the server's own parse (UserStatusAutomation): walk VAVAILABILITY
			// components and their AVAILABLE children, reading each RRULE's BYDAY.
			foreach ($vcalendar->getComponents() as $component) {
				if ($component->name !== 'VAVAILABILITY') {
					continue;
				}
				foreach ($component->getComponents() as $available) {
					if ($available->name !== 'AVAILABLE' || !isset($available->RRULE)) {
						continue;
					}
					if (!preg_match('/BYDAY=([^;]+)/', (string)$available->RRULE, $m)) {
						continue;
					}
					foreach (explode(',', $m[1]) as $token) {
						// Tokens may carry an ordinal prefix (e.g. "2MO"); keep the last two letters.
						$code = strtoupper(substr(trim($token), -2));
						if (isset(self::BYDAY[$code])) {
							$days[self::BYDAY[$code]] = true;
						}
					}
				}
			}
			if ($days === []) {
				return null;
			}
			$result = array_keys($days);
			sort($result);
			return $result;
		} catch (\Throwable $e) {
			$this->logger->debug('Absence: could not parse availability for ' . $uid, ['exception' => $e]);
			return null;
		}
	}

	/** Raw VAVAILABILITY string from the user's CalDAV inbox, or null if unset. */
	private function readAvailability(string $uid): ?string {
		try {
			$path = 'calendars/' . $uid . '/inbox';
			$name = '{' . Plugin::NS_CALDAV . '}calendar-availability';
			$props = $this->propertyMapper->findPropertyByPathAndName($uid, $path, $name);
			$value = $props[0] ?? null;
			return $value !== null ? $value->getPropertyvalue() : null;
		} catch (\Throwable $e) {
			$this->logger->debug('Absence: could not read availability for ' . $uid, ['exception' => $e]);
			return null;
		}
	}
}
