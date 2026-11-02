<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\ConfigLexicon;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;

/**
 * HR reminds the employees whose holiday region code is missing or only names
 * a country that has regional holidays (§7.2). Someone reminded in the last
 * week is left alone.
 *
 * @psalm-type Person = array{uid: string, name: string, code: string, example: ?string, remindedAt: ?string}
 * @psalm-type Reminder = array{send: list<Person>, recent: list<Person>}
 */
class RegionReminderService {
	private const QUIET_SECONDS = 7 * 24 * 3600;

	public function __construct(
		private EmployeeDirectory $employees,
		private ConfigService $config,
		private IUserConfig $userConfig,
		private IUserManager $userManager,
		private NotificationService $notifications,
		private HolidayService $holidays,
		private ClockService $clock,
		private IEventDispatcher $dispatcher,
	) {
	}

	/**
	 * Who would get the reminder now, and who was reminded too recently.
	 *
	 * @return Reminder
	 */
	public function plan(): array {
		$codes = $this->config->getRegionCodes();
		$since = $this->clock->now()->getTimestamp() - self::QUIET_SECONDS;
		$plan = ['send' => [], 'recent' => []];
		foreach ($this->employees->listAll() as $uid) {
			$uid = (string)$uid;
			$code = $codes[$uid] ?? '';
			if (!$this->holidays->needsRegion($code)) {
				continue;
			}
			$reminded = $this->userConfig->getValueInt($uid, ConfigService::APP_ID, ConfigLexicon::KEY_REGION_REMINDED_AT);
			$plan[$reminded > $since ? 'recent' : 'send'][] = [
				'uid' => $uid,
				'name' => $this->userManager->getDisplayName($uid) ?? $uid,
				'code' => $code,
				'example' => $code === '' ? null : $this->holidays->exampleRegion($code),
				'remindedAt' => $reminded > 0 ? (new \DateTimeImmutable('@' . $reminded))->format(\DateTimeInterface::ATOM) : null,
			];
		}
		return $plan;
	}

	/**
	 * @return Reminder
	 */
	public function send(string $actor): array {
		$plan = $this->plan();
		$now = $this->clock->now()->getTimestamp();
		foreach ($plan['send'] as $person) {
			$this->notifications->notifyMissingRegion($person['uid'], $person['code'], $person['example']);
			$this->userConfig->setValueInt($person['uid'], ConfigService::APP_ID, ConfigLexicon::KEY_REGION_REMINDED_AT, $now);
		}
		if ($plan['send'] !== []) {
			$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(
				'Region code reminder sent to %s employees by %s', [count($plan['send']), $actor],
			));
		}
		return $plan;
	}
}
