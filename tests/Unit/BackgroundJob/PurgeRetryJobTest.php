<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\BackgroundJob;

use OCA\Absence\BackgroundJob\PurgeRetryJob;
use OCA\Absence\Db\PurgeRetryMapper;
use OCA\Absence\Service\UserPurgeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PurgeRetryJobTest extends TestCase {
	private PurgeRetryMapper&MockObject $retries;
	private UserPurgeService&MockObject $purge;
	private IUserManager&MockObject $userManager;
	/** @var list<string> */
	private array $removed = [];

	protected function setUp(): void {
		parent::setUp();
		$this->retries = $this->createMock(PurgeRetryMapper::class);
		$this->retries->method('remove')->willReturnCallback(function (string $uid): void {
			$this->removed[] = $uid;
		});
		$this->purge = $this->createMock(UserPurgeService::class);
		$this->userManager = $this->createMock(IUserManager::class);
	}

	public function testAPurgeThatGoesThroughLeavesTheListAndOneThatFailsStays(): void {
		$this->retries->method('findAll')->willReturn(['fixed', 'broken']);
		$this->userManager->method('userExists')->willReturn(false);
		$this->purge->expects(self::exactly(2))->method('purge')->willReturnCallback(static function (string $uid): void {
			if ($uid === 'broken') {
				throw new \RuntimeException('storage down');
			}
		});

		$this->runJob();

		self::assertSame(['fixed'], $this->removed);
	}

	public function testAUidThatIsAnAccountAgainIsNeverPurgedAndLeavesTheList(): void {
		$this->retries->method('findAll')->willReturn(['back']);
		$this->userManager->method('userExists')->with('back')->willReturn(true);
		$this->purge->expects(self::never())->method('purge');

		$this->runJob();

		self::assertSame(['back'], $this->removed);
	}

	private function runJob(): void {
		$job = new PurgeRetryJob(
			$this->createMock(ITimeFactory::class),
			$this->retries,
			$this->purge,
			$this->userManager,
			$this->createMock(LoggerInterface::class),
		);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
