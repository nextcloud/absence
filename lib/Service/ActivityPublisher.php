<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\LeaveRequest;
use OCP\Activity\IManager;
use Psr\Log\LoggerInterface;

/**
 * Publishes activity-stream events for the audit trail (spec §11).
 */
class ActivityPublisher {
	public const TYPE = 'absence';

	public const SUBJECT_CREATED = 'request_created';
	public const SUBJECT_APPROVED = 'request_approved';
	public const SUBJECT_REJECTED = 'request_rejected';
	public const SUBJECT_CANCELLED = 'request_cancelled';
	public const SUBJECT_ESCALATED = 'request_escalated';
	public const SUBJECT_WITHDRAWAL = 'request_withdrawal';
	public const SUBJECT_BALANCE_ADJUSTED = 'balance_adjusted';

	public function __construct(
		private IManager $activityManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string[] $affectedUsers
	 * @param array<string,mixed> $params
	 */
	public function publish(string $subject, array $params, array $affectedUsers, ?LeaveRequest $request = null): void {
		$affectedUsers = array_values(array_unique(array_filter($affectedUsers)));
		if ($affectedUsers === []) {
			return;
		}
		try {
			foreach ($affectedUsers as $uid) {
				$event = $this->activityManager->generateEvent();
				$event->setApp(ConfigService::APP_ID)
					->setType(self::TYPE)
					->setAffectedUser($uid)
					->setAuthor($this->activityManager->getCurrentUserId() ?? '')
					->setSubject($subject, $params);
				if ($request !== null) {
					$event->setObject('absence_request', (int)$request->getId());
				}
				$this->activityManager->publish($event);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Absence: failed to publish activity', ['exception' => $e]);
		}
	}
}
