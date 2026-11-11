<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\BackgroundJob;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Service\CarryOverReminderService;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reminds managers about pending requests that are approaching the escalation
 * window (spec §9), and employees about carried-over days about to expire
 * (§6.2). Runs daily.
 */
class ReminderJob extends TimedJob {
	use WorkingDaysTrait;

	/** How far back a run looks for the days earlier runs missed */
	public const MAX_CATCH_UP_DAYS = 7;

	public function __construct(
		ITimeFactory $time,
		private ClockService $clock,
		private LeaveRequestMapper $requestMapper,
		private NotificationService $notifications,
		private ConfigService $config,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private ManagerResolver $managerResolver,
		private CarryOverReminderService $carryOverReminder,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		// Its own once-a-year marker and its own dates (§6.2), so it comes before
		// the weekend and cut-off exits below. A failure must not cost the managers
		// their reminders.
		try {
			$this->carryOverReminder->remind();
		} catch (\Throwable $e) {
			$this->logger->error('Absence: carry-over reminder failed', ['app' => 'absence', 'exception' => $e]);
		}

		$window = max(1, $this->config->getEscalationWindowDays());
		$lead = max(0, $this->config->getReminderLeadDays());
		$reminderThreshold = max(1, $window - $lead);

		// Remind only requests that crossed the threshold since the last run, so a
		// pending request is reminded once, not on every run. The cut-off is
		// `threshold` working days ago; the one of the last run is stored, because
		// the server runs the job again after an app upgrade and may skip a day.
		// Skip weekend runs entirely: nobody reads a reminder then, and Monday
		// resolves to the same cut-off.
		// Deliberately the server's timezone, not a user's: this decides which
		// requests are due across the whole company, and there is no user to ask.
		$today = $this->clock->serverNow()->setTime(0, 0);
		if ((int)$today->format('N') > 5) {
			return;
		}
		$before = $this->subtractWorkingDays($today, $reminderThreshold);
		$last = $this->appConfig->getValueInt(ConfigService::APP_ID, ConfigLexicon::KEY_REMINDER_CUTOFF);
		if ($last >= $before->getTimestamp()) {
			return;
		}
		// The first run takes the one-working-day band. Later runs continue where
		// the last one stopped, but not further back than the catch-up limit: after
		// a month without cron the older requests are escalated, not reminded.
		$after = $last === 0
			? $this->subtractWorkingDays($before, 1)
			: max($before->setTimestamp($last), $before->modify('-' . self::MAX_CATCH_UP_DAYS . ' days'));

		$requests = $this->requestMapper->findPendingCreatedBetween($after, $before);
		// Stored before sending: a run that dies half way must not remind the
		// first half again.
		$this->appConfig->setValueInt(ConfigService::APP_ID, ConfigLexicon::KEY_REMINDER_CUTOFF, $before->getTimestamp());
		foreach ($requests as $request) {
			// The employee's manager today, not the one the request was made under
			$managerUid = $this->managerResolver->getManagerUid($request->getEmployeeUid());
			if ($managerUid === null) {
				continue;
			}
			// One failed reminder must not abort the run: this cohort is reminded
			// exactly once (the next run starts after it), so a throw here would
			// silently skip every later request in the band for good.
			try {
				$this->notifications->notifyReminder($request, $managerUid);
			} catch (\Throwable $e) {
				$this->logger->error('Absence: failed to send reminder', ['app' => 'absence', 'request' => $request->getId(), 'exception' => $e]);
			}
		}
	}
}
