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
use Psr\Log\LoggerInterface;

/**
 * Earlier versions added `absence` to the system `log.condition.apps` on every
 * install and update. The app no longer touches the system config (§11), so
 * this takes that entry out again and leaves the rest of the condition alone.
 */
class RemoveAuditLogCondition implements IRepairStep {
	public function __construct(
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Remove Absence from the log condition of earlier versions';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$condition = $this->config->getSystemValue('log.condition', []);
		if (!is_array($condition) || !is_array($condition['apps'] ?? null) || !in_array('absence', $condition['apps'], true)) {
			return;
		}
		$apps = array_values(array_filter($condition['apps'], static fn (mixed $app): bool => $app !== 'absence'));
		if ($apps === []) {
			unset($condition['apps']);
		} else {
			$condition['apps'] = $apps;
		}
		try {
			if ($condition === []) {
				$this->config->deleteSystemValue('log.condition');
			} else {
				$this->config->setSystemValue('log.condition', $condition);
			}
		} catch (\Throwable $e) {
			// A read-only config.php: the entry stays until an admin removes it
			$this->logger->warning('Absence: could not remove absence from log.condition.apps in config.php', ['exception' => $e]);
		}
	}
}
