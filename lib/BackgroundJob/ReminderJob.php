<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\BackgroundJob;

use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Reminds managers about pending requests that are approaching the escalation
 * window (spec §9). Runs daily.
 */
class ReminderJob extends TimedJob {
	use WorkingDaysTrait;

	public function __construct(
		ITimeFactory $time,
		private LeaveRequestMapper $requestMapper,
		private NotificationService $notifications,
		private ConfigService $config,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		$window = max(1, $this->config->getEscalationWindowDays());
		$lead = max(0, $this->config->getReminderLeadDays());
		$reminderThreshold = max(1, $window - $lead);

		// Remind only requests that cross the threshold *on this run* — i.e. created
		// in the one-working-day band ending `threshold` working days ago — so a
		// pending request is reminded once, not on every daily run (avoids spam).
		// Skip weekend runs entirely: Sat/Sun/Mon would all resolve to the same
		// working-day band and remind the same cohort up to three times.
		$today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
		if ((int)$today->format('N') > 5) {
			return;
		}
		$before = $this->subtractWorkingDays($today, $reminderThreshold);
		$after = $this->subtractWorkingDays($before, 1);

		foreach ($this->requestMapper->findPendingCreatedBetween($after, $before) as $request) {
			if ($request->getManagerUid() !== null) {
				$this->notifications->notifyReminder($request, $request->getManagerUid());
			}
		}
	}
}
