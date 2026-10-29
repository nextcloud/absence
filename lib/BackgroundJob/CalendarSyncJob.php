<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\BackgroundJob;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Service\CalendarService;
use OCA\Absence\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;

/**
 * Writes the calendar events of approved leave that has none (§10): imported
 * leave, leave approved while calendar writing was off, and writes that failed.
 * A batch per run, in id order, so a request that keeps failing never holds
 * the others back; it is tried again on the next pass.
 */
class CalendarSyncJob extends TimedJob {
	public const BATCH_SIZE = 200;

	public function __construct(
		ITimeFactory $time,
		private LeaveRequestMapper $requestMapper,
		private CalendarService $calendar,
		private ConfigService $config,
		private IAppConfig $appConfig,
	) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		if (!$this->config->isCalDavPersonalEnabled() && !$this->config->isCalDavSharedEnabled()) {
			return;
		}
		$cursor = $this->appConfig->getValueInt(ConfigService::APP_ID, ConfigLexicon::KEY_CALENDAR_SYNC_CURSOR);
		$requests = $this->requestMapper->findApprovedWithoutCalendarEvent($cursor, self::BATCH_SIZE);
		foreach ($requests as $request) {
			$uri = $this->calendar->onApproved($request);
			if ($uri !== null) {
				$request->setCalendarEventUri($uri);
				$this->requestMapper->update($request);
			}
			$cursor = $request->getId();
		}
		// Back to the start once through, for the ones that failed
		$this->appConfig->setValueInt(ConfigService::APP_ID, ConfigLexicon::KEY_CALENDAR_SYNC_CURSOR, count($requests) < self::BATCH_SIZE ? 0 : $cursor);
	}
}
