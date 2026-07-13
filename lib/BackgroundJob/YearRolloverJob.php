<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\BackgroundJob;

use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EntitlementService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;

/**
 * Computes carry-over into the new year and expires stale carry-over (spec §6.2).
 * Runs daily but only acts once per calendar year (idempotent via a stored marker).
 */
class YearRolloverJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private EntitlementService $entitlementService,
		private IAppConfig $appConfig,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$currentYear = (int)date('Y');

		// Carry-over from last year into this year — run once per year.
		$lastRollover = $this->appConfig->getValueInt(ConfigService::APP_ID, 'last_rollover_year', 0);
		if ($lastRollover < $currentYear) {
			$this->entitlementService->rollover($currentYear - 1);
			$this->appConfig->setValueInt(ConfigService::APP_ID, 'last_rollover_year', $currentYear);
		}

		// Expire carry-over that has passed its configured expiry date (safe to run daily).
		$this->entitlementService->expireCarryOver($currentYear);
	}
}
