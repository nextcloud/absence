<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Exception\ValidationException;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;

/**
 * Typed access to the app's admin settings (§12) and per-user personal settings.
 * Keys, types and defaults are declared once in the config lexicon
 * ({@see ConfigLexicon}); this service adds domain-level value validation.
 */
class ConfigService {
	public const APP_ID = 'absence';

	// ISO weekdays that count as working days when nothing is detected: Mon–Fri.
	public const DEFAULT_WORK_WEEKDAYS = '1,2,3,4,5';

	public const CARRYOVER_NONE = 'none';
	public const CARRYOVER_UNLIMITED = 'unlimited';
	public const CARRYOVER_CAPPED = 'capped';

	public const VISIBILITY_NEUTRAL = 'neutral';
	public const VISIBILITY_REVEAL = 'reveal';

	// Upper bounds of the number settings. A stored value above its bound is read as the bound.
	/** The largest value the DECIMAL(5,1) entitlement columns store. */
	public const MAX_DAYS = 9999.0;
	/** The longest window, lead or notice period: a year. */
	public const MAX_PERIOD_DAYS = 365;
	/** The highest threshold of people off at once. */
	public const MAX_CONCURRENT = 9999;

	public function __construct(
		private IAppConfig $appConfig,
		private IUserConfig $userConfig,
		private IGroupManager $groupManager,
		private IL10N $l,
	) {
	}

	public function getHrGroup(): string {
		return $this->appConfig->getValueString(self::APP_ID, ConfigLexicon::KEY_HR_GROUP);
	}

	/** Group whose members count as employees; '' means every non-guest account (§2.2). */
	public function getEmployeesGroup(): string {
		return $this->appConfig->getValueString(self::APP_ID, ConfigLexicon::KEY_EMPLOYEES_GROUP);
	}

	/**
	 * Whether the app is set up: the HR and employees groups are chosen and
	 * exist (§12). Until then nobody uses the app but admins and HR, in the settings.
	 */
	public function isConfigured(): bool {
		foreach ([$this->getHrGroup(), $this->getEmployeesGroup()] as $group) {
			if ($group === '' || !$this->groupManager->groupExists($group)) {
				return false;
			}
		}
		return true;
	}

	public function getDefaultEntitlement(): float {
		return min(self::MAX_DAYS, $this->appConfig->getValueFloat(self::APP_ID, ConfigLexicon::KEY_DEFAULT_ENTITLEMENT));
	}

	public function getEscalationWindowDays(): int {
		return min(self::MAX_PERIOD_DAYS, $this->appConfig->getValueInt(self::APP_ID, ConfigLexicon::KEY_ESCALATION_WINDOW));
	}

	public function getReminderLeadDays(): int {
		return min(self::MAX_PERIOD_DAYS, $this->appConfig->getValueInt(self::APP_ID, ConfigLexicon::KEY_REMINDER_LEAD));
	}

	public function getCarryOverPolicy(): string {
		return $this->appConfig->getValueString(self::APP_ID, ConfigLexicon::KEY_CARRYOVER_POLICY);
	}

	public function getCarryOverCap(): float {
		return min(self::MAX_DAYS, $this->appConfig->getValueFloat(self::APP_ID, ConfigLexicon::KEY_CARRYOVER_CAP));
	}

	/** @return string '' or 'MM-DD' expiry day within the new year. */
	public function getCarryOverExpiry(): string {
		return $this->appConfig->getValueString(self::APP_ID, ConfigLexicon::KEY_CARRYOVER_EXPIRY);
	}

	public function getMaxConcurrentAbsences(): int {
		return min(self::MAX_CONCURRENT, $this->appConfig->getValueInt(self::APP_ID, ConfigLexicon::KEY_MAX_CONCURRENT));
	}

	/**
	 * Calendar days of advance notice a leave request is expected to give. Zero
	 * disables the short-notice warning entirely.
	 */
	public function getNoticePeriodDays(): int {
		return min(self::MAX_PERIOD_DAYS, $this->appConfig->getValueInt(self::APP_ID, ConfigLexicon::KEY_NOTICE_PERIOD));
	}

	public function isCalDavPersonalEnabled(): bool {
		return $this->appConfig->getValueBool(self::APP_ID, ConfigLexicon::KEY_CALDAV_PERSONAL);
	}

	public function isCalDavSharedEnabled(): bool {
		return $this->appConfig->getValueBool(self::APP_ID, ConfigLexicon::KEY_CALDAV_SHARED);
	}

	public function canEveryoneEditHolidays(): bool {
		return $this->appConfig->getValueBool(self::APP_ID, ConfigLexicon::KEY_HOLIDAYS_EVERYONE);
	}

	/** @return string self::VISIBILITY_NEUTRAL|self::VISIBILITY_REVEAL */
	public function getSharedCalendarVisibility(): string {
		return $this->appConfig->getValueString(self::APP_ID, ConfigLexicon::KEY_SHARED_VISIBILITY);
	}

	/** Whether emails quote what people wrote on a request: reasons, decision comments, comments (§11). */
	public function isMailQuoteTextEnabled(): bool {
		return $this->appConfig->getValueBool(self::APP_ID, ConfigLexicon::KEY_MAIL_QUOTE_TEXT);
	}

	/** Where the handbook for everyone lives, '' when there is none. */
	public function getHandbookUrl(): string {
		return $this->appConfig->getValueString(self::APP_ID, ConfigLexicon::KEY_HANDBOOK_URL);
	}

	/** Where the handbook for HR lives, '' when there is none. */
	public function getHrHandbookUrl(): string {
		return $this->appConfig->getValueString(self::APP_ID, ConfigLexicon::KEY_HR_HANDBOOK_URL);
	}

	/**
	 * The whole admin config as a plain array for the settings UI / SPA bootstrap.
	 *
	 * @return array<string,mixed>
	 */
	public function getAdminConfig(): array {
		return [
			ConfigLexicon::KEY_HR_GROUP => $this->getHrGroup(),
			ConfigLexicon::KEY_EMPLOYEES_GROUP => $this->getEmployeesGroup(),
			ConfigLexicon::KEY_DEFAULT_ENTITLEMENT => $this->getDefaultEntitlement(),
			ConfigLexicon::KEY_ESCALATION_WINDOW => $this->getEscalationWindowDays(),
			ConfigLexicon::KEY_REMINDER_LEAD => $this->getReminderLeadDays(),
			ConfigLexicon::KEY_CARRYOVER_POLICY => $this->getCarryOverPolicy(),
			ConfigLexicon::KEY_CARRYOVER_CAP => $this->getCarryOverCap(),
			ConfigLexicon::KEY_CARRYOVER_EXPIRY => $this->getCarryOverExpiry(),
			ConfigLexicon::KEY_MAX_CONCURRENT => $this->getMaxConcurrentAbsences(),
			ConfigLexicon::KEY_NOTICE_PERIOD => $this->getNoticePeriodDays(),
			ConfigLexicon::KEY_CALDAV_PERSONAL => $this->isCalDavPersonalEnabled(),
			ConfigLexicon::KEY_CALDAV_SHARED => $this->isCalDavSharedEnabled(),
			ConfigLexicon::KEY_HOLIDAYS_EVERYONE => $this->canEveryoneEditHolidays(),
			ConfigLexicon::KEY_SHARED_VISIBILITY => $this->getSharedCalendarVisibility(),
			ConfigLexicon::KEY_MAIL_QUOTE_TEXT => $this->isMailQuoteTextEnabled(),
			ConfigLexicon::KEY_HANDBOOK_URL => $this->getHandbookUrl(),
			ConfigLexicon::KEY_HR_HANDBOOK_URL => $this->getHrHandbookUrl(),
		];
	}

	/**
	 * The holiday region code a person set for themselves (§7.2).
	 *
	 * @return array{holiday_country: string, holiday_region: string}
	 */
	public function getPersonalConfig(string $uid): array {
		return [
			ConfigLexicon::KEY_HOLIDAY_COUNTRY => $this->userConfig->getValueString($uid, self::APP_ID, ConfigLexicon::KEY_HOLIDAY_COUNTRY),
			ConfigLexicon::KEY_HOLIDAY_REGION => $this->userConfig->getValueString($uid, self::APP_ID, ConfigLexicon::KEY_HOLIDAY_REGION),
		];
	}

	/**
	 * `DE` and `BY` make `DE-BY`; no country, no code.
	 */
	public static function regionCode(string $country, string $region): string {
		return $country === '' ? '' : ($region === '' ? $country : $country . '-' . $region);
	}

	/**
	 * Everybody's region code in two queries, for lists of many people. People
	 * without one are left out.
	 *
	 * @return array<string, string> uid => region code
	 */
	public function getRegionCodes(): array {
		$regions = $this->userConfig->getValuesByUsers(self::APP_ID, ConfigLexicon::KEY_HOLIDAY_REGION);
		$codes = [];
		foreach ($this->userConfig->getValuesByUsers(self::APP_ID, ConfigLexicon::KEY_HOLIDAY_COUNTRY) as $uid => $country) {
			$code = self::regionCode((string)$country, (string)($regions[$uid] ?? ''));
			if ($code !== '') {
				$codes[(string)$uid] = $code;
			}
		}
		return $codes;
	}

	/**
	 * @throws ValidationException for a holiday country or region that cannot be part of a region code (§7.2)
	 */
	public function setPersonalValue(string $uid, string $key, string $value): void {
		$this->setPersonalValues($uid, [$key => $value]);
	}

	/**
	 * Checks every value first and writes none of them while one is invalid.
	 *
	 * @param array<string, string> $values
	 * @throws ValidationException for a holiday country or region that cannot be part of a region code (§7.2)
	 */
	public function setPersonalValues(string $uid, array $values): void {
		foreach ($values as $key => $value) {
			$pattern = match ($key) {
				ConfigLexicon::KEY_HOLIDAY_COUNTRY => '/^([A-Z]{2})?$/',
				ConfigLexicon::KEY_HOLIDAY_REGION => '/^([A-Z0-9]{1,3})?$/',
				default => null,
			};
			if ($pattern !== null) {
				$values[$key] = strtoupper(trim($value));
				if (preg_match($pattern, $values[$key]) !== 1) {
					throw new ValidationException($this->l->t('"%s" is not a region code: use a country like DE or a region like DE-BY', [$values[$key]]));
				}
			}
		}
		foreach ($values as $key => $value) {
			$this->userConfig->setValueString($uid, self::APP_ID, $key, $value);
		}
	}

	/**
	 * @throws ValidationException on out-of-range or unknown values/keys
	 */
	public function setAdminValue(string $key, mixed $value): void {
		$this->setAdminValues([$key => $value]);
	}

	/**
	 * Checks every value first and writes none of them while one is invalid.
	 *
	 * @param array<string, mixed> $values
	 * @throws ValidationException on out-of-range or unknown values/keys
	 */
	public function setAdminValues(array $values): void {
		foreach ($this->normalizeAdminValues($values) as $key => $value) {
			match (true) {
				is_bool($value) => $this->appConfig->setValueBool(self::APP_ID, $key, $value),
				is_int($value) => $this->appConfig->setValueInt(self::APP_ID, $key, $value),
				is_float($value) => $this->appConfig->setValueFloat(self::APP_ID, $key, $value),
				default => $this->appConfig->setValueString(self::APP_ID, $key, $value),
			};
		}
	}

	/**
	 * The values as they would be stored.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, int|float|bool|string>
	 * @throws ValidationException on the first out-of-range or unknown value/key
	 */
	public function normalizeAdminValues(array $values): array {
		$normalized = [];
		foreach ($values as $key => $value) {
			$normalized[(string)$key] = $this->normalizeAdminValue((string)$key, $value);
		}
		return $normalized;
	}

	/**
	 * @throws ValidationException
	 */
	private function normalizeAdminValue(string $key, mixed $value): int|float|bool|string {
		switch ($key) {
			case ConfigLexicon::KEY_ESCALATION_WINDOW:
			case ConfigLexicon::KEY_REMINDER_LEAD:
			case ConfigLexicon::KEY_MAX_CONCURRENT:
			case ConfigLexicon::KEY_NOTICE_PERIOD:
				$int = filter_var($value, FILTER_VALIDATE_INT);
				$max = $key === ConfigLexicon::KEY_MAX_CONCURRENT ? self::MAX_CONCURRENT : self::MAX_PERIOD_DAYS;
				if ($int === false || is_bool($value) || $int < 0 || $int > $max) {
					throw new ValidationException($this->l->t('This setting must be a whole number between 0 and %s.', [$max]));
				}
				return $int;
			case ConfigLexicon::KEY_DEFAULT_ENTITLEMENT:
			case ConfigLexicon::KEY_CARRYOVER_CAP:
				$float = is_numeric($value) ? (float)$value : NAN;
				if (!is_finite($float) || $float < 0.0 || $float > self::MAX_DAYS || $float !== floor($float)) {
					throw new ValidationException($this->l->t('This setting must be a whole number between 0 and %s.', [self::MAX_DAYS]));
				}
				return $float;
			case ConfigLexicon::KEY_CALDAV_PERSONAL:
			case ConfigLexicon::KEY_CALDAV_SHARED:
			case ConfigLexicon::KEY_HOLIDAYS_EVERYONE:
			case ConfigLexicon::KEY_MAIL_QUOTE_TEXT:
				if (!is_bool($value)) {
					throw new ValidationException($this->l->t('This setting must be on or off.'));
				}
				return $value;
			case ConfigLexicon::KEY_CARRYOVER_POLICY:
				if (!in_array($value, [self::CARRYOVER_NONE, self::CARRYOVER_CAPPED, self::CARRYOVER_UNLIMITED], true)) {
					throw new ValidationException($this->l->t('Invalid carry-over policy.'));
				}
				return $value;
			case ConfigLexicon::KEY_SHARED_VISIBILITY:
				if (!in_array($value, [self::VISIBILITY_NEUTRAL, self::VISIBILITY_REVEAL], true)) {
					throw new ValidationException($this->l->t('Invalid shared-calendar visibility.'));
				}
				return $value;
			case ConfigLexicon::KEY_HANDBOOK_URL:
			case ConfigLexicon::KEY_HR_HANDBOOK_URL:
				if (!is_string($value) || ($value !== '' && !$this->isHandbookLink($value))) {
					throw new ValidationException($this->l->t('The handbook must be a link starting with https:// or /, or empty.'));
				}
				return $value;
			case ConfigLexicon::KEY_CARRYOVER_EXPIRY:
				if (!is_string($value) || ($value !== '' && !$this->isMonthDay($value))) {
					throw new ValidationException($this->l->t('The carry-over expiry must be empty or a day in MM-DD format.'));
				}
				return $value;
			case ConfigLexicon::KEY_HR_GROUP:
				$gid = is_string($value) ? $value : '';
				// Reject an empty or unknown group: an empty HR group silently disables
				// the HR role (nobody can act on escalations), and a typo'd group name
				// would do the same without any feedback.
				if ($gid === '' || !$this->groupManager->groupExists($gid)) {
					throw new ValidationException($this->l->t('The chosen HR group does not exist.'));
				}
				return $gid;
			case ConfigLexicon::KEY_EMPLOYEES_GROUP:
				$gid = is_string($value) ? $value : '';
				// Required: the app stays closed until there is one (§12)
				if ($gid === '') {
					throw new ValidationException($this->l->t('Choose the employees group.'));
				}
				if (!$this->groupManager->groupExists($gid)) {
					throw new ValidationException($this->l->t('The chosen employees group does not exist.'));
				}
				return $gid;
			default:
				throw new ValidationException($this->l->t('Unknown setting: %s', [$key]));
		}
	}

	/**
	 * A day of the year as MM-DD. The month is checked in a leap year, so 02-29
	 * is a day.
	 */
	private function isMonthDay(string $value): bool {
		return preg_match('/^(\d{2})-(\d{2})\z/', $value, $parts) === 1 && checkdate((int)$parts[1], (int)$parts[2], 2000);
	}

	/**
	 * A web link that parses and names a host, or a path on this server. Never
	 * javascript: or data:
	 */
	private function isHandbookLink(string $value): bool {
		if (preg_match('#^https?://\S+\z#i', $value) === 1) {
			return filter_var($value, FILTER_VALIDATE_URL) !== false;
		}
		// A browser reads a second "/" or "\" as the start of another host
		return preg_match('#^/[^/\\\\\s]\S*\z#', $value) === 1;
	}
}
