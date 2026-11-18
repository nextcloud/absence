<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Command;

use OCA\Absence\Command\PurgeDeletedUsers;
use OCA\Absence\Service\UserPurgeService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeDeletedUsersTest extends TestCase {
	private UserPurgeService&MockObject $purge;
	private CommandTester $tester;

	protected function setUp(): void {
		parent::setUp();
		$this->purge = $this->createMock(UserPurgeService::class);
		$this->purge->method('findOrphanUids')->willReturn(['gone-1', 'gone-2']);
		$this->tester = new CommandTester(new PurgeDeletedUsers($this->purge));
	}

	public function testWithoutForceItOnlyLists(): void {
		$this->purge->expects(self::never())->method('purge');

		self::assertSame(0, $this->tester->execute([]));
		self::assertStringContainsString("  gone-1\n  gone-2\n", $this->tester->getDisplay());
	}

	public function testWithForceItPurgesEachAndReportsAFailure(): void {
		$purged = [];
		$this->purge->method('purge')->willReturnCallback(static function (string $uid) use (&$purged): void {
			if ($uid === 'gone-1') {
				throw new \RuntimeException('storage down');
			}
			$purged[] = $uid;
		});

		self::assertSame(1, $this->tester->execute(['--force' => true]));
		self::assertSame(['gone-2'], $purged);
		self::assertStringContainsString('Could not purge gone-1: storage down', $this->tester->getDisplay());
	}
}
