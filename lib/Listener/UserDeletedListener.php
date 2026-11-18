<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Listener;

use OCA\Absence\Db\PurgeRetryMapper;
use OCA\Absence\Service\UserPurgeService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Removes a deleted user's leave data for GDPR compliance (spec §17). A purge
 * that fails is retried by {@see \OCA\Absence\BackgroundJob\PurgeRetryJob}.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private UserPurgeService $purge,
		private PurgeRetryMapper $retries,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$uid = $event->getUser()->getUID();
		try {
			$this->purge->purge($uid);
		} catch (\Throwable $e) {
			$this->retries->add($uid);
			$this->logger->error('Absence: purging the data of a deleted account failed, it is retried daily', [
				'app' => 'absence',
				'employee' => $uid,
				'exception' => $e,
			]);
		}
	}
}
