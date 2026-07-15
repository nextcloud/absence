<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Typed access to the app's admin settings (§12) and per-user personal settings.
 */
class ConfigService {
	public const APP_ID = 'absence';

	// Per-user setting keys with defaults. Used to prefill the "Working days"
	// field on new requests (weekday counting minus public holidays).
	public const KEY_WORK_WEEKDAYS = 'work_weekdays';
	public const KEY_HOLIDAY_COUNTRY = 'holiday_country';
	public const KEY_HOLIDAY_REGION = 'holiday_region';

	// ISO weekdays that count as working days by default: Mon–Fri (1..5, Sun=7).
	public const DEFAULT_WORK_WEEKDAYS = '1,2,3,4,5';

	// Admin setting keys with defaults.
	public const KEY_HR_GROUP = 'hr_group';
	public const KEY_DEFAULT_ENTITLEMENT = 'default_entitlement';
	public const KEY_ESCALATION_WINDOW = 'escalation_window_days';
	public const KEY_REMINDER_LEAD = 'reminder_lead_days';
	public const KEY_CARRYOVER_POLICY = 'carryover_policy';
	public const KEY_CARRYOVER_CAP = 'carryover_cap';
	public const KEY_CARRYOVER_EXPIRY = 'carryover_expiry';
	public const KEY_MAX_CONCURRENT = 'max_concurrent_absences';
	public const KEY_CALDAV_PERSONAL = 'caldav_personal';
	public const KEY_CALDAV_SHARED = 'caldav_shared';
	public const KEY_SHARED_VISIBILITY = 'shared_calendar_visibility';

	public const CARRYOVER_NONE = 'none';
	public const CARRYOVER_UNLIMITED = 'unlimited';
	public const CARRYOVER_CAPPED = 'capped';

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
	) {
	}

	public function getHrGroup(): string {
		return $this->appConfig->getValueString(self::APP_ID, self::KEY_HR_GROUP, 'hr');
	}

	public function getDefaultEntitlement(): float {
		return (float)$this->appConfig->getValueString(self::APP_ID, self::KEY_DEFAULT_ENTITLEMENT, '25');
	}

	public function getEscalationWindowDays(): int {
		return $this->appConfig->getValueInt(self::APP_ID, self::KEY_ESCALATION_WINDOW, 3);
	}

	public function getReminderLeadDays(): int {
		return $this->appConfig->getValueInt(self::APP_ID, self::KEY_REMINDER_LEAD, 1);
	}

	public function getCarryOverPolicy(): string {
		return $this->appConfig->getValueString(self::APP_ID, self::KEY_CARRYOVER_POLICY, self::CARRYOVER_CAPPED);
	}

	public function getCarryOverCap(): float {
		return (float)$this->appConfig->getValueString(self::APP_ID, self::KEY_CARRYOVER_CAP, '5');
	}

	/** @return string '' or 'MM-DD' expiry day within the new year. */
	public function getCarryOverExpiry(): string {
		return $this->appConfig->getValueString(self::APP_ID, self::KEY_CARRYOVER_EXPIRY, '');
	}

	public function getMaxConcurrentAbsences(): int {
		return $this->appConfig->getValueInt(self::APP_ID, self::KEY_MAX_CONCURRENT, 2);
	}

	public function isCalDavPersonalEnabled(): bool {
		return $this->appConfig->getValueBool(self::APP_ID, self::KEY_CALDAV_PERSONAL, true);
	}

	public function isCalDavSharedEnabled(): bool {
		return $this->appConfig->getValueBool(self::APP_ID, self::KEY_CALDAV_SHARED, true);
	}

	/** @return string 'neutral'|'reveal' */
	public function getSharedCalendarVisibility(): string {
		return $this->appConfig->getValueString(self::APP_ID, self::KEY_SHARED_VISIBILITY, 'neutral');
	}

	/**
	 * The whole admin config as a plain array for the settings UI / SPA bootstrap.
	 *
	 * @return array<string,mixed>
	 */
	public function getAdminConfig(): array {
		return [
			self::KEY_HR_GROUP => $this->getHrGroup(),
			self::KEY_DEFAULT_ENTITLEMENT => $this->getDefaultEntitlement(),
			self::KEY_ESCALATION_WINDOW => $this->getEscalationWindowDays(),
			self::KEY_REMINDER_LEAD => $this->getReminderLeadDays(),
			self::KEY_CARRYOVER_POLICY => $this->getCarryOverPolicy(),
			self::KEY_CARRYOVER_CAP => $this->getCarryOverCap(),
			self::KEY_CARRYOVER_EXPIRY => $this->getCarryOverExpiry(),
			self::KEY_MAX_CONCURRENT => $this->getMaxConcurrentAbsences(),
			self::KEY_CALDAV_PERSONAL => $this->isCalDavPersonalEnabled(),
			self::KEY_CALDAV_SHARED => $this->isCalDavSharedEnabled(),
			self::KEY_SHARED_VISIBILITY => $this->getSharedCalendarVisibility(),
		];
	}

	/**
	 * Per-user settings used to prefill the "Working days" field (§7 prefill).
	 *
	 * @return array{work_weekdays: string, holiday_country: string, holiday_region: string}
	 */
	public function getPersonalConfig(string $uid): array {
		return [
			// Empty means "no override" — resolve() then uses the detected Availability,
			// falling back to Mon–Fri only when nothing is detected.
			self::KEY_WORK_WEEKDAYS => $this->config->getUserValue($uid, self::APP_ID, self::KEY_WORK_WEEKDAYS, ''),
			self::KEY_HOLIDAY_COUNTRY => $this->config->getUserValue($uid, self::APP_ID, self::KEY_HOLIDAY_COUNTRY, ''),
			self::KEY_HOLIDAY_REGION => $this->config->getUserValue($uid, self::APP_ID, self::KEY_HOLIDAY_REGION, ''),
		];
	}

	public function setPersonalValue(string $uid, string $key, string $value): void {
		$this->config->setUserValue($uid, self::APP_ID, $key, $value);
	}

	public function setAdminValue(string $key, mixed $value): void {
		switch ($key) {
			case self::KEY_ESCALATION_WINDOW:
			case self::KEY_REMINDER_LEAD:
			case self::KEY_MAX_CONCURRENT:
				$this->appConfig->setValueInt(self::APP_ID, $key, (int)$value);
				break;
			case self::KEY_CALDAV_PERSONAL:
			case self::KEY_CALDAV_SHARED:
				$this->appConfig->setValueBool(self::APP_ID, $key, (bool)$value);
				break;
			default:
				$this->appConfig->setValueString(self::APP_ID, $key, (string)$value);
		}
	}
}
