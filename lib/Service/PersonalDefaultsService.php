<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\DAV\CalDAV\Schedule\Plugin;
use OCA\DAV\Db\PropertyMapper;
use OCP\Accounts\IAccountManager;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

/**
 * Works out sensible defaults for the request form's "Working days" prefill from
 * data the user already maintains in Nextcloud, so they need not re-enter it:
 *
 *  - working weekdays ← their Availability (/settings/user/availability)
 *  - holiday country  ← their locale, falling back to their phone country code
 *
 * Every detected value is only a suggestion: the per-user override stored via
 * ConfigService always wins, and the request field stays editable.
 */
class PersonalDefaultsService {
	/** Availability RRULE BYDAY tokens → ISO weekday (Mon=1..Sun=7). */
	private const BYDAY = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

	// Phone calling code → ISO country, longest-prefix matched. Not exhaustive —
	// it is a best-effort suggestion the user can override.
	// ponytail: hand-picked common codes; swap for libphonenumber if coverage matters.
	private const CALLING_CODES = [
		'1' => 'US', '7' => 'RU', '20' => 'EG', '27' => 'ZA', '30' => 'GR', '31' => 'NL',
		'32' => 'BE', '33' => 'FR', '34' => 'ES', '36' => 'HU', '39' => 'IT', '40' => 'RO',
		'41' => 'CH', '43' => 'AT', '44' => 'GB', '45' => 'DK', '46' => 'SE', '47' => 'NO',
		'48' => 'PL', '49' => 'DE', '51' => 'PE', '52' => 'MX', '53' => 'CU', '54' => 'AR',
		'55' => 'BR', '56' => 'CL', '57' => 'CO', '58' => 'VE', '60' => 'MY', '61' => 'AU',
		'62' => 'ID', '63' => 'PH', '64' => 'NZ', '65' => 'SG', '66' => 'TH', '81' => 'JP',
		'82' => 'KR', '84' => 'VN', '86' => 'CN', '90' => 'TR', '91' => 'IN', '92' => 'PK',
		'93' => 'AF', '94' => 'LK', '95' => 'MM', '98' => 'IR', '351' => 'PT', '352' => 'LU',
		'353' => 'IE', '354' => 'IS', '358' => 'FI', '359' => 'BG', '370' => 'LT', '371' => 'LV',
		'372' => 'EE', '380' => 'UA', '385' => 'HR', '386' => 'SI', '420' => 'CZ', '421' => 'SK',
		'972' => 'IL', '971' => 'AE', '966' => 'SA',
	];

	public function __construct(
		private IConfig $config,
		private ConfigService $appConfig,
		private IAccountManager $accountManager,
		private IUserManager $userManager,
		private PropertyMapper $propertyMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Everything the settings page and the SPA session need: the effective values
	 * (override ?: detected ?: default) plus what was detected, so the settings UI
	 * can show feedback and whether Availability has been filled out.
	 *
	 * @return array<string,mixed>
	 */
	public function resolve(string $uid): array {
		$stored = $this->appConfig->getPersonalConfig($uid);
		$overrideWeekdays = $stored[ConfigService::KEY_WORK_WEEKDAYS];
		$overrideCountry = $stored[ConfigService::KEY_HOLIDAY_COUNTRY];
		$region = $stored[ConfigService::KEY_HOLIDAY_REGION];

		$detectedWeekdays = $this->detectWorkingWeekdays($uid);
		$detectedCsv = $detectedWeekdays !== null ? implode(',', $detectedWeekdays) : null;
		$detectedCountry = $this->detectCountry($uid);

		return [
			// Effective values consumed by the request-form prefill (always a CSV string).
			'workWeekdays' => $overrideWeekdays !== '' ? $overrideWeekdays : ($detectedCsv ?? ConfigService::DEFAULT_WORK_WEEKDAYS),
			'holidayCountry' => $overrideCountry !== '' ? $overrideCountry : ($detectedCountry ?? ''),
			'holidayRegion' => $region,
			// Detection details for the settings UI.
			'availabilitySet' => $detectedWeekdays !== null,
			'workWeekdaysDetected' => $detectedWeekdays,
			'workWeekdaysOverride' => $overrideWeekdays,
			'holidayCountryDetected' => $detectedCountry,
			'holidayCountryOverride' => $overrideCountry,
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

	/** Suggested ISO country code from the user's locale, then phone; null if unknown. */
	public function detectCountry(string $uid): ?string {
		$locale = $this->config->getUserValue($uid, 'core', 'locale', '');
		if (str_contains($locale, '_')) {
			$region = strtoupper(substr($locale, strpos($locale, '_') + 1));
			if (preg_match('/^[A-Z]{2}$/', $region)) {
				return $region;
			}
		}
		return $this->countryFromPhone($uid);
	}

	private function countryFromPhone(string $uid): ?string {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return null;
		}
		try {
			$phone = $this->accountManager->getAccount($user)->getProperty(IAccountManager::PROPERTY_PHONE)->getValue();
		} catch (\Throwable $e) {
			return null;
		}
		$digits = preg_replace('/\D/', '', (string)$phone);
		if ($phone === '' || !str_starts_with(trim($phone), '+') || $digits === '') {
			// Without a leading "+" the calling code is ambiguous — do not guess.
			return null;
		}
		for ($len = 3; $len >= 1; $len--) {
			$prefix = substr($digits, 0, $len);
			if (isset(self::CALLING_CODES[$prefix])) {
				return self::CALLING_CODES[$prefix];
			}
		}
		return null;
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
