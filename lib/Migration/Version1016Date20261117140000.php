<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use Closure;
use OCA\Absence\ConfigLexicon;
use OCA\Absence\Service\ConfigService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The HR group has no default any more. An install that never chose one used
 * the group `hr`: it is stored, so its members keep the HR role. A new install
 * only runs the schema part of migrations and starts without an HR group.
 */
class Version1016Date20261117140000 extends SimpleMigrationStep {
	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
	) {
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->appConfig->hasKey(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP)
			&& $this->groupManager->groupExists('hr')) {
			$this->appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, 'hr');
			$output->info('Kept the group "hr" as the HR group');
		}
	}
}
