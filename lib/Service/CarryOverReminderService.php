<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\LeaveTypeMapper;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Tells employees, once a year and some weeks ahead, that carried-over days
 * they have not used yet are about to expire (§6.2).
 */
class CarryOverReminderService {
	/** How many days before the expiry date the reminder goes out */
	public const LEAD_DAYS = 30;

	public function __construct(
		private BalanceService $balanceService,
		private EmployeeDirectory $employees,
		private LeaveTypeMapper $leaveTypeMapper,
		private NotificationService $notifications,
		private ClockService $clock,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Send the reminder of the running year when it is due and was not sent yet.
	 * Safe to call every day: a run after the lead day catches up until the
	 * expiry date, after which there is nothing left to warn about.
	 *
	 * @return int the number of employees reminded
	 */
	public function remind(): int {
		// company policy, run from a background job: the server's date
		$year = $this->clock->serverYear();
		$today = $this->clock->serverToday();
		$expiryDate = $this->balanceService->carryOverExpiryDate($year);
		if ($expiryDate === null || $today > $expiryDate) {
			return 0;
		}
		if ($today < (new \DateTimeImmutable($expiryDate))->modify('-' . self::LEAD_DAYS . ' days')->format('Y-m-d')) {
			return 0;
		}
		if ($this->appConfig->getValueInt(ConfigService::APP_ID, ConfigLexicon::KEY_CARRYOVER_REMINDER_YEAR) >= $year) {
			return 0;
		}
		// With an expiry early in January the reminder is due before the rollover
		// has carried anything over: wait for it, or nobody would be reminded.
		if ($this->appConfig->getValueInt(ConfigService::APP_ID, ConfigLexicon::KEY_LAST_ROLLOVER_YEAR) < $year) {
			return 0;
		}
		// Stored before sending, like the reminder cut-off: a run that dies half
		// way must not remind the first half again.
		$this->appConfig->setValueInt(ConfigService::APP_ID, ConfigLexicon::KEY_CARRYOVER_REMINDER_YEAR, $year);

		// Confidential types (§5.7) are not on the employee's own balance either.
		$hrOnlyIds = $this->leaveTypeMapper->hrOnlyTypeIds();
		$reminded = 0;
		foreach ($this->balanceService->getBalancesForEmployees($this->employees->listAll(), $year) as $uid => $rows) {
			$days = 0.0;
			foreach ($rows as $row) {
				if (!in_array($row['typeId'], $hrOnlyIds, true)) {
					$days += (float)$row['carryOverExpiring'];
				}
			}
			if ($days <= 0.0) {
				continue;
			}
			try {
				$this->notifications->notifyCarryOverExpiring((string)$uid, round($days, 1), $expiryDate);
				$reminded++;
			} catch (\Throwable $e) {
				$this->logger->error('Absence: failed to send carry-over reminder', ['app' => 'absence', 'exception' => $e]);
			}
		}
		return $reminded;
	}

	/**
	 * The reminder as a sentence, the same in the notification and the email.
	 *
	 * @param string $expiryDate 'YYYY-MM-DD'
	 */
	public static function sentence(IL10N $l, float $days, string $expiryDate): string {
		return $l->n(
			'%1$s carried-over day expires on %2$s.',
			'%1$s carried-over days expire on %2$s.',
			(int)ceil($days),
			[
				rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.'),
				(string)$l->l('date', new \DateTime($expiryDate), ['width' => 'long']),
			],
		);
	}

	/** What to do about it, under the sentence. */
	public static function hint(IL10N $l): string {
		return $l->t('Leave that starts on or before that date uses them up first.');
	}
}
