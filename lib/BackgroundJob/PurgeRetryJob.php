<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\BackgroundJob;

use OCA\Absence\Db\PurgeRetryMapper;
use OCA\Absence\Service\UserPurgeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Runs the purges of deleted accounts that failed (§17) again, daily, until
 * they go through. A uid that belongs to an account again is dropped from the
 * list and its data kept: it is that account's now.
 */
class PurgeRetryJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private PurgeRetryMapper $retries,
		private UserPurgeService $purge,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		foreach ($this->retries->findAll() as $uid) {
			if ($this->userManager->userExists($uid)) {
				$this->retries->remove($uid);
				$this->logger->warning('Absence: an account has the uid of a deleted account again, its data is not purged', [
					'app' => 'absence',
					'employee' => $uid,
				]);
				continue;
			}
			try {
				$this->purge->purge($uid);
				$this->retries->remove($uid);
			} catch (\Throwable $e) {
				$this->logger->error('Absence: purging the data of a deleted account failed again', [
					'app' => 'absence',
					'employee' => $uid,
					'exception' => $e,
				]);
			}
		}
	}
}
