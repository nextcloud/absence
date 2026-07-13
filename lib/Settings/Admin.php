<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Settings;

use OCA\Absence\Service\ConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private ConfigService $config,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('adminConfig', $this->config->getAdminConfig());
		Util::addScript(ConfigService::APP_ID, 'absence-admin-settings');
		return new TemplateResponse(ConfigService::APP_ID, 'admin-settings');
	}

	public function getSection(): string {
		return ConfigService::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
