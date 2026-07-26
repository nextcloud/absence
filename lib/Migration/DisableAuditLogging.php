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
 * Reverts {@see EnableAuditLogging}: removes `absence` from `log.condition.apps`
 * on uninstall, leaving any other admin-configured conditions untouched.
 */
class DisableAuditLogging implements IRepairStep {
	public function __construct(
		private IConfig $config,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Remove Absence from the always-on log condition';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$condition = $this->config->getSystemValue('log.condition', []);
		if (!is_array($condition) || !isset($condition['apps']) || !is_array($condition['apps'])) {
			return;
		}
		$apps = array_values(array_filter($condition['apps'], static fn ($app) => $app !== 'absence'));
		if ($apps === $condition['apps']) {
			return;
		}
		if ($apps === []) {
			unset($condition['apps']);
		} else {
			$condition['apps'] = $apps;
		}
		$this->config->setSystemValue('log.condition', $condition);
		$output->info('Absence: removed from log.condition.apps.');
	}
}
