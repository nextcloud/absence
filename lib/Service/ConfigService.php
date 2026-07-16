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
	) {
	}

	public function getHrGroup(): string {
		return $this->appConfig->getValueString(self::APP_ID, ConfigLexicon::KEY_HR_GROUP);
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

	public function isCalDavPersonalEnabled(): bool {
		return $this->appConfig->getValueBool(self::APP_ID, ConfigLexicon::KEY_CALDAV_PERSONAL);
	}

	public function isCalDavSharedEnabled(): bool {
		return $this->appConfig->getValueBool(self::APP_ID, ConfigLexicon::KEY_CALDAV_SHARED);
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
			ConfigLexicon::KEY_DEFAULT_ENTITLEMENT => $this->getDefaultEntitlement(),
			ConfigLexicon::KEY_ESCALATION_WINDOW => $this->getEscalationWindowDays(),
			ConfigLexicon::KEY_REMINDER_LEAD => $this->getReminderLeadDays(),
			ConfigLexicon::KEY_CARRYOVER_POLICY => $this->getCarryOverPolicy(),
			ConfigLexicon::KEY_CARRYOVER_CAP => $this->getCarryOverCap(),
			ConfigLexicon::KEY_CARRYOVER_EXPIRY => $this->getCarryOverExpiry(),
			ConfigLexicon::KEY_MAX_CONCURRENT => $this->getMaxConcurrentAbsences(),
			ConfigLexicon::KEY_CALDAV_PERSONAL => $this->isCalDavPersonalEnabled(),
			ConfigLexicon::KEY_CALDAV_SHARED => $this->isCalDavSharedEnabled(),
			ConfigLexicon::KEY_SHARED_VISIBILITY => $this->getSharedCalendarVisibility(),
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
			ConfigLexicon::KEY_WORK_WEEKDAYS => $this->userConfig->getValueString($uid, self::APP_ID, ConfigLexicon::KEY_WORK_WEEKDAYS),
			ConfigLexicon::KEY_HOLIDAY_COUNTRY => $this->userConfig->getValueString($uid, self::APP_ID, ConfigLexicon::KEY_HOLIDAY_COUNTRY),
			ConfigLexicon::KEY_HOLIDAY_REGION => $this->userConfig->getValueString($uid, self::APP_ID, ConfigLexicon::KEY_HOLIDAY_REGION),
		];
	}

	public function setPersonalValue(string $uid, string $key, string $value): void {
		$this->userConfig->setValueString($uid, self::APP_ID, $key, $value);
	}

	/**
	 * @throws ValidationException on out-of-range or unknown values/keys
	 */
	public function setAdminValue(string $key, mixed $value): void {
		switch ($key) {
			case ConfigLexicon::KEY_ESCALATION_WINDOW:
			case ConfigLexicon::KEY_REMINDER_LEAD:
			case ConfigLexicon::KEY_MAX_CONCURRENT:
				$int = (int)$value;
				if ($int < 0) {
					throw new ValidationException('This setting must be zero or greater.');
				}
				$this->appConfig->setValueInt(self::APP_ID, $key, $int);
				break;
			case ConfigLexicon::KEY_DEFAULT_ENTITLEMENT:
			case ConfigLexicon::KEY_CARRYOVER_CAP:
				$float = (float)$value;
				if ($float < 0.0) {
					throw new ValidationException('This setting must be zero or greater.');
				}
				$this->appConfig->setValueFloat(self::APP_ID, $key, $float);
				break;
			case ConfigLexicon::KEY_CALDAV_PERSONAL:
			case ConfigLexicon::KEY_CALDAV_SHARED:
				$this->appConfig->setValueBool(self::APP_ID, $key, (bool)$value);
				break;
			case ConfigLexicon::KEY_CARRYOVER_POLICY:
				if (!in_array($value, [self::CARRYOVER_NONE, self::CARRYOVER_CAPPED, self::CARRYOVER_UNLIMITED], true)) {
					throw new ValidationException('Invalid carry-over policy.');
				}
				$this->appConfig->setValueString(self::APP_ID, $key, $value);
				break;
			case ConfigLexicon::KEY_SHARED_VISIBILITY:
				if (!in_array($value, [self::VISIBILITY_NEUTRAL, self::VISIBILITY_REVEAL], true)) {
					throw new ValidationException('Invalid shared-calendar visibility.');
				}
				$this->appConfig->setValueString(self::APP_ID, $key, $value);
				break;
			case ConfigLexicon::KEY_CARRYOVER_EXPIRY:
				$expiry = (string)$value;
				if ($expiry !== '' && !preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/', $expiry)) {
					throw new ValidationException('The carry-over expiry must be empty or a day in MM-DD format.');
				}
				$this->appConfig->setValueString(self::APP_ID, $key, $expiry);
				break;
			case ConfigLexicon::KEY_HR_GROUP:
				$this->appConfig->setValueString(self::APP_ID, $key, (string)$value);
				break;
			default:
				throw new ValidationException('Unknown setting: ' . $key);
		}
	}
}
