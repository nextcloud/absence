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
		return $this->appConfig->getValueFloat(self::APP_ID, ConfigLexicon::KEY_DEFAULT_ENTITLEMENT);
	}

	public function getEscalationWindowDays(): int {
		return $this->appConfig->getValueInt(self::APP_ID, ConfigLexicon::KEY_ESCALATION_WINDOW);
	}

	public function getReminderLeadDays(): int {
		return $this->appConfig->getValueInt(self::APP_ID, ConfigLexicon::KEY_REMINDER_LEAD);
	}

	public function getCarryOverPolicy(): string {
		return $this->appConfig->getValueString(self::APP_ID, ConfigLexicon::KEY_CARRYOVER_POLICY);
	}

	public function getCarryOverCap(): float {
		return $this->appConfig->getValueFloat(self::APP_ID, ConfigLexicon::KEY_CARRYOVER_CAP);
	}

	/** @return string '' or 'MM-DD' expiry day within the new year. */
	public function getCarryOverExpiry(): string {
		return $this->appConfig->getValueString(self::APP_ID, ConfigLexicon::KEY_CARRYOVER_EXPIRY);
	}

	public function getMaxConcurrentAbsences(): int {
		return $this->appConfig->getValueInt(self::APP_ID, ConfigLexicon::KEY_MAX_CONCURRENT);
	}

	/**
	 * Calendar days of advance notice a leave request is expected to give. Zero
	 * disables the short-notice warning entirely.
	 */
	public function getNoticePeriodDays(): int {
		return $this->appConfig->getValueInt(self::APP_ID, ConfigLexicon::KEY_NOTICE_PERIOD);
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
	 * @throws ValidationException for a holiday country or region that cannot be part of a region code (§7.2)
	 */
	public function setPersonalValue(string $uid, string $key, string $value): void {
		$pattern = match ($key) {
			ConfigLexicon::KEY_HOLIDAY_COUNTRY => '/^([A-Z]{2})?$/',
			ConfigLexicon::KEY_HOLIDAY_REGION => '/^([A-Z0-9]{1,3})?$/',
			default => null,
		};
		if ($pattern !== null) {
			$value = strtoupper(trim($value));
			if (preg_match($pattern, $value) !== 1) {
				throw new ValidationException($this->l->t('"%s" is not a region code: use a country like DE or a region like DE-BY', [$value]));
			}
		}
		$this->userConfig->setValueString($uid, self::APP_ID, $key, $value);
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
				if ($int === false || is_bool($value) || $int < 0) {
					throw new ValidationException($this->l->t('This setting must be a whole number, zero or greater.'));
				}
				return $int;
			case ConfigLexicon::KEY_DEFAULT_ENTITLEMENT:
			case ConfigLexicon::KEY_CARRYOVER_CAP:
				if (!is_numeric($value) || (float)$value < 0.0) {
					throw new ValidationException($this->l->t('This setting must be a number, zero or greater.'));
				}
				return (float)$value;
			case ConfigLexicon::KEY_CALDAV_PERSONAL:
			case ConfigLexicon::KEY_CALDAV_SHARED:
			case ConfigLexicon::KEY_HOLIDAYS_EVERYONE:
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
			case ConfigLexicon::KEY_CARRYOVER_EXPIRY:
				if (!is_string($value) || ($value !== '' && !preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/', $value))) {
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
}
