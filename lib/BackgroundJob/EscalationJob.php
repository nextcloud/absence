<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\BackgroundJob;

use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\RequestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Escalates pending requests a manager has not acted on within the configured
 * window (spec §5.4). Runs hourly.
 */
class EscalationJob extends TimedJob {
	use WorkingDaysTrait;

	public function __construct(
		ITimeFactory $time,
		private ClockService $clock,
		private LeaveRequestMapper $requestMapper,
		private RequestService $requestService,
		private ConfigService $config,
		private LoggerInterface $logger,
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
		// Deliberately the server's timezone, not a user's: this decides which
		// requests are due across the whole company, and there is no user to ask.
		$today = $this->clock->serverNow()->setTime(0, 0);
		$cutoff = $this->subtractWorkingDays($today, $window);
		foreach ($this->requestMapper->findPendingOlderThan($cutoff) as $request) {
			// One request that fails to escalate (e.g. a bad notification recipient)
			// must not abort the run and leave every later request stuck — and stuck
			// forever, since each hourly re-run would hit the same bad row first.
			try {
				$this->requestService->escalate($request);
			} catch (\Throwable $e) {
				$this->logger->error('Absence: failed to escalate request', ['app' => 'absence', 'request' => $request->getId(), 'exception' => $e]);
			}
		}

		// §5.4a: escalate early when the manager cannot possibly decide in time —
		// they are on approved leave today, and it lasts beyond the request's own
		// escalation deadline. Waiting out the window would only delay the answer
		// HR is going to give anyway. escalate() flips conditionally, so a request
		// the first pass already moved (or a manager deciding this very second)
		// is never clobbered.
		$todayIso = $today->format('Y-m-d');
		/** @var array<string,array<string,bool>> manager => deadline => away-through-deadline */
		$awayCache = [];
		foreach ($this->requestMapper->findPendingOlderThan($this->clock->serverNow()) as $request) {
			$managerUid = $request->getManagerUid();
			if ($managerUid === null) {
				continue;
			}
			$createdDay = \DateTimeImmutable::createFromInterface($request->getCreatedAt())
				->setTimezone($today->getTimezone())
				->setTime(0, 0);
			$deadline = $this->addWorkingDays($createdDay, $window)->format('Y-m-d');
			$awayCache[$managerUid][$deadline] ??= $this->requestMapper
				->hasApprovedAbsenceCovering($managerUid, $todayIso, $deadline);
			if ($awayCache[$managerUid][$deadline]) {
				try {
					$this->requestService->escalate($request);
				} catch (\Throwable $e) {
					$this->logger->error('Absence: failed to early-escalate request', ['app' => 'absence', 'request' => $request->getId(), 'exception' => $e]);
				}
			}
		}
	}
}
