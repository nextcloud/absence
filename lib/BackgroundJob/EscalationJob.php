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
	use WorkingDaysTrait;

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

	#[\Override]
	protected function run($argument): void {
		// The window counts working days (Mon–Fri, §5.4): a request filed on Friday
		// does not burn its manager's window over the weekend. With the midnight
		// cut-off, a request escalates once its manager had the full window.
		$window = max(1, $this->config->getEscalationWindowDays());
		$today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
		$cutoff = $this->subtractWorkingDays($today, $window);
		foreach ($this->requestMapper->findPendingOlderThan($cutoff) as $request) {
			$this->requestService->escalate($request);
		}
	}
}
