<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\BackgroundJob;

use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\RequestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Escalates pending requests a manager has not acted on within the configured
 * window (spec §5.4). Runs hourly.
 */
class EscalationJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private LeaveRequestMapper $requestMapper,
		private RequestService $requestService,
		private ConfigService $config,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$window = max(1, $this->config->getEscalationWindowDays());
		$cutoff = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
		$cutoff = $cutoff->modify('-' . $window . ' days');
		foreach ($this->requestMapper->findPendingOlderThan($cutoff) as $request) {
			$this->requestService->escalate($request);
		}
	}
}
