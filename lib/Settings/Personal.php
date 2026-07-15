<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Settings;

use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\PersonalDefaultsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private PersonalDefaultsService $personalDefaults,
		private IUserSession $userSession,
	) {
	}

	public function getForm(): TemplateResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		$this->initialState->provideInitialState('personalConfig', $this->personalDefaults->resolve($uid));
		Util::addScript(ConfigService::APP_ID, 'absence-personal-settings');
		return new TemplateResponse(ConfigService::APP_ID, 'personal-settings');
	}

	public function getSection(): string {
		// Append to the built-in Availability page (/settings/user/availability)
		// rather than a separate Absence section.
		return 'availability';
	}

	public function getPriority(): int {
		// Below the core Availability form (priority 10).
		return 50;
	}
}
