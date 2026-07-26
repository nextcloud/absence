<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence;

use OCA\Absence\Service\ConfigService;
use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\ILexicon;
use OCP\Config\Lexicon\Strictness;
use OCP\Config\ValueType;

/**
 * Config lexicon for the absence app: the single declaration of every
 * app-config and user-config key with its type, default and documentation.
 *
 * Please add & manage config keys in this file and keep it up to date!
 *
 * {@see ILexicon}
 */
class ConfigLexicon implements ILexicon {
	// Admin settings (§12).
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

	// Internal state.
	public const KEY_LAST_ROLLOVER_YEAR = 'last_rollover_year';

	// Per-user settings used to prefill the working-day count (§7).
	public const KEY_WORK_WEEKDAYS = 'work_weekdays';
	public const KEY_HOLIDAY_COUNTRY = 'holiday_country';
	public const KEY_HOLIDAY_REGION = 'holiday_region';

	#[\Override]
	public function getStrictness(): Strictness {
		return Strictness::NOTICE;
	}

	#[\Override]
	public function getAppConfigs(): array {
		return [
			new Entry(self::KEY_HR_GROUP, ValueType::STRING, 'hr',
				'Group whose members have the HR role'),
			new Entry(self::KEY_DEFAULT_ENTITLEMENT, ValueType::FLOAT, 25.0,
				'Default annual-leave entitlement in working days'),
			new Entry(self::KEY_ESCALATION_WINDOW, ValueType::INT, 3,
				'Days before an unanswered pending request is escalated to HR', lazy: true),
			new Entry(self::KEY_REMINDER_LEAD, ValueType::INT, 1,
				'Days before escalation that the manager is reminded of a pending request', lazy: true),
			new Entry(self::KEY_CARRYOVER_POLICY, ValueType::STRING, ConfigService::CARRYOVER_CAPPED,
				'Carry-over policy at year end: none, capped or unlimited', lazy: true),
			new Entry(self::KEY_CARRYOVER_CAP, ValueType::FLOAT, 5.0,
				'Maximum days carried over into the new year (capped policy)', lazy: true),
			new Entry(self::KEY_CARRYOVER_EXPIRY, ValueType::STRING, '',
				'Day (MM-DD) in the new year when carried-over days expire; empty = never', lazy: true),
			new Entry(self::KEY_MAX_CONCURRENT, ValueType::INT, 2,
				'Concurrent team absences above which a coverage warning is shown'),
			new Entry(self::KEY_CALDAV_PERSONAL, ValueType::BOOL, true,
				'Write approved leave to the employee\'s personal calendar', lazy: true),
			new Entry(self::KEY_CALDAV_SHARED, ValueType::BOOL, true,
				'Write approved leave to the shared team calendar', lazy: true),
			new Entry(self::KEY_SHARED_VISIBILITY, ValueType::STRING, ConfigService::VISIBILITY_NEUTRAL,
				'Shared-calendar event titles: neutral ("Absent") or reveal the leave type', lazy: true),
			new Entry(self::KEY_LAST_ROLLOVER_YEAR, ValueType::INT, 0,
				'Internal marker: last year already processed by the rollover job', lazy: true),
		];
	}

	#[\Override]
	public function getUserConfigs(): array {
		return [
			new Entry(self::KEY_WORK_WEEKDAYS, ValueType::STRING, '',
				'ISO weekdays (comma-separated, Mon=1) that count as working days; empty = detect from availability'),
			new Entry(self::KEY_HOLIDAY_COUNTRY, ValueType::STRING, '',
				'Country code for public-holiday detection in the working-day prefill'),
			new Entry(self::KEY_HOLIDAY_REGION, ValueType::STRING, '',
				'Region/state code refining the public-holiday country'),
		];
	}
}
