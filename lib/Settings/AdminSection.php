<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Settings;

use OCA\Absence\Service\ConfigService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IURLGenerator $url,
		private IL10N $l,
	) {
	}

	public function getID(): string {
		return ConfigService::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Absence');
	}

	public function getPriority(): int {
		return 80;
	}

	public function getIcon(): string {
		return $this->url->imagePath(ConfigService::APP_ID, 'app-dark.svg');
	}
}
