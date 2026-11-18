<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Listener;

use OCA\Absence\Db\PurgeRetryMapper;
use OCA\Absence\Listener\UserDeletedListener;
use OCA\Absence\Service\UserPurgeService;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserDeletedListenerTest extends TestCase {
	private UserPurgeService&MockObject $purge;
	private PurgeRetryMapper&MockObject $retries;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->purge = $this->createMock(UserPurgeService::class);
		$this->retries = $this->createMock(PurgeRetryMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	public function testAFailedPurgeIsLoggedAndKeptForTheRetry(): void {
		$this->purge->expects(self::once())->method('purge')->with('gone')->willThrowException(new \RuntimeException('storage down'));
		$this->retries->expects(self::once())->method('add')->with('gone');
		$this->logger->expects(self::once())->method('error');

		$this->handle('gone');
	}

	public function testAPurgeThatGoesThroughLeavesNothingToRetry(): void {
		$this->purge->expects(self::once())->method('purge')->with('gone');
		$this->retries->expects(self::never())->method('add');

		$this->handle('gone');
	}

	private function handle(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		(new UserDeletedListener($this->purge, $this->retries, $this->logger))->handle(new UserDeletedEvent($user));
	}
}
