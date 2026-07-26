<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Guarantees the app's audit entries are always written to nextcloud.log,
 * regardless of the instance's global log level.
 *
 * Nextcloud's `log.condition.apps` overrides the effective log level to DEBUG for
 * matching apps (see \OC\Log::getLogLevel). Since every audit entry is tagged with
 * `['app' => 'absence']`, adding `absence` here makes those entries bypass the
 * level filter. We merge into any existing condition rather than replacing it.
 *
 * Runs on install and after every update (idempotent). Removed again on uninstall
 * by {@see DisableAuditLogging}.
 */
class EnableAuditLogging implements IRepairStep {
	public function __construct(
		private IConfig $config,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Ensure Absence audit actions are always logged';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$condition = $this->config->getSystemValue('log.condition', []);
		if (!is_array($condition)) {
			$condition = [];
		}
		$apps = $condition['apps'] ?? [];
		if (!is_array($apps)) {
			$apps = [];
		}
		if (in_array('absence', $apps, true)) {
			return;
		}
		$apps[] = 'absence';
		$condition['apps'] = array_values($apps);
		$this->config->setSystemValue('log.condition', $condition);
		$output->info('Absence: audit actions will always be written to nextcloud.log (added to log.condition.apps).');
	}
}
