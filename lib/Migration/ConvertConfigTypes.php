<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Service\ConfigService;
use OCP\Exceptions\AppConfigUnknownKeyException;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Re-write existing app-config values through their lexicon-typed setters so the
 * stored type and lazyness match the config lexicon. Values written before the
 * lexicon existed were stored as plain non-lazy strings; reading those through
 * a FLOAT/lazy lexicon entry would fail or miss them.
 */
class ConvertConfigTypes implements IRepairStep {
	private const TYPES = [
		ConfigLexicon::KEY_HR_GROUP => 'string',
		ConfigLexicon::KEY_DEFAULT_ENTITLEMENT => 'float',
		ConfigLexicon::KEY_ESCALATION_WINDOW => 'int',
		ConfigLexicon::KEY_REMINDER_LEAD => 'int',
		ConfigLexicon::KEY_CARRYOVER_POLICY => 'string',
		ConfigLexicon::KEY_CARRYOVER_CAP => 'float',
		ConfigLexicon::KEY_CARRYOVER_EXPIRY => 'string',
		ConfigLexicon::KEY_MAX_CONCURRENT => 'int',
		ConfigLexicon::KEY_CALDAV_PERSONAL => 'bool',
		ConfigLexicon::KEY_CALDAV_SHARED => 'bool',
		ConfigLexicon::KEY_SHARED_VISIBILITY => 'string',
		ConfigLexicon::KEY_LAST_ROLLOVER_YEAR => 'int',
	];

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function getName(): string {
		return 'Align stored absence config values with the config lexicon';
	}

	public function run(IOutput $output): void {
		$converted = 0;
		foreach (self::TYPES as $key => $type) {
			try {
				// getDetails() returns the raw stored value without type enforcement.
				$details = $this->appConfig->getDetails(ConfigService::APP_ID, $key);
			} catch (AppConfigUnknownKeyException) {
				continue; // never set: the lexicon default applies, nothing to convert
			}
			$value = (string)($details['value'] ?? '');
			$this->appConfig->deleteKey(ConfigService::APP_ID, $key);
			match ($type) {
				'int' => $this->appConfig->setValueInt(ConfigService::APP_ID, $key, (int)$value),
				'float' => $this->appConfig->setValueFloat(ConfigService::APP_ID, $key, (float)$value),
				'bool' => $this->appConfig->setValueBool(ConfigService::APP_ID, $key, in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true)),
				default => $this->appConfig->setValueString(ConfigService::APP_ID, $key, $value),
			};
			$converted++;
		}
		if ($converted > 0) {
			$output->info('Re-typed ' . $converted . ' absence config values to match the config lexicon.');
		}
	}
}
