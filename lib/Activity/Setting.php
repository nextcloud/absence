<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Activity;

use OCA\Absence\Service\ConfigService;
use OCP\Activity\ISetting;
use OCP\IL10N;

class Setting implements ISetting {
	public function __construct(
		private IL10N $l,
	) {
	}

	#[\Override]
	public function getIdentifier(): string {
		return ConfigService::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('Leave requests and approvals');
	}

	#[\Override]
	public function getPriority(): int {
		return 60;
	}

	#[\Override]
	public function canChangeStream(): bool {
		return true;
	}

	#[\Override]
	public function isDefaultEnabledStream(): bool {
		return true;
	}

	#[\Override]
	public function canChangeMail(): bool {
		return true;
	}

	#[\Override]
	public function isDefaultEnabledMail(): bool {
		return false;
	}
}
