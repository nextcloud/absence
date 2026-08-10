<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\ManagerResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The line-manager relationship decides who may approve and who may see whose
 * leave, so its edge cases (self-management, dangling uids, LDAP errors) are
 * authorization edge cases too.
 */
class ManagerResolverTest extends TestCase {
	private IUserManager&MockObject $userManager;
	private LoggerInterface&MockObject $logger;
	private ManagerResolver $service;

	protected function setUp(): void {
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new ManagerResolver(
			$this->userManager,
			new EmployeeDirectory($this->userManager, $this->createMock(IGroupManager::class)),
			$this->logger,
		);
	}

	/**
	 * Wire up a directory: uid => list of configured manager uids.
	 *
	 * @param array<string,string[]> $directory
	 */
	private function directory(array $directory): void {
		$users = [];
		foreach ($directory as $uid => $managerUids) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getManagerUids')->willReturn($managerUids);
			$users[$uid] = $user;
		}
		$this->userManager->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => $users[$uid] ?? null,
		);
		$this->userManager->method('userExists')->willReturnCallback(
			static fn (string $uid): bool => isset($users[$uid]),
		);
		$this->userManager->method('callForAllUsers')->willReturnCallback(
			static function (callable $fn) use ($users): void {
				foreach ($users as $user) {
					$fn($user);
				}
			},
		);
	}

	public function testResolvesTheConfiguredManager(): void {
		$this->directory(['alice' => ['bob'], 'bob' => []]);
		$this->assertSame('bob', $this->service->getManagerUid('alice'));
	}

	public function testEmployeeWithoutAManagerResolvesToNull(): void {
		$this->directory(['alice' => [], 'bob' => []]);
		$this->assertNull($this->service->getManagerUid('alice'));
	}

	public function testUnknownUserResolvesToNull(): void {
		$this->directory(['bob' => []]);
		$this->assertNull($this->service->getManagerUid('ghost'));
	}

	public function testSelfManagementIsIgnored(): void {
		// Otherwise the employee could approve their own leave.
		$this->directory(['alice' => ['alice'], 'bob' => []]);
		$this->assertNull($this->service->getManagerUid('alice'));
	}

	public function testDanglingManagerUidIsIgnored(): void {
		// A manager who has left the company: the request must escalate to HR
		// rather than name a decider who cannot act.
		$this->directory(['alice' => ['departed']]);
		$this->assertNull($this->service->getManagerUid('alice'));
	}

	public function testFirstValidManagerWins(): void {
		$this->directory(['alice' => ['departed', 'bob'], 'bob' => []]);
		$this->assertSame('bob', $this->service->getManagerUid('alice'));
	}

	public function testBlankManagerUidIsIgnored(): void {
		$this->directory(['alice' => ['', '  ', 'bob'], 'bob' => []]);
		$this->assertSame('bob', $this->service->getManagerUid('alice'));
	}

	public function testABackendFailureResolvesToNullRatherThanThrowing(): void {
		// LDAP being unreachable must not take the whole app down.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getManagerUids')->willThrowException(new \RuntimeException('LDAP down'));
		$this->userManager->method('get')->willReturn($user);

		$this->assertNull($this->service->getManagerUid('alice'));
	}

	public function testDirectReportsAndIsManagerOf(): void {
		$this->directory([
			'alice' => ['bob'],
			'carol' => ['bob'],
			'dave' => [],
			'bob' => [],
		]);

		$this->assertSame(['alice', 'carol'], $this->service->getDirectReports('bob'));
		$this->assertTrue($this->service->isManagerOf('bob', 'alice'));
		$this->assertFalse($this->service->isManagerOf('bob', 'dave'));
		// Not a manager of anyone: no reports, and not somebody else's manager.
		$this->assertSame([], $this->service->getDirectReports('dave'));
		$this->assertFalse($this->service->isManagerOf('alice', 'carol'));
	}

	public function testPeersShareAManagerAndExcludeSelf(): void {
		$this->directory([
			'alice' => ['bob'],
			'carol' => ['bob'],
			'bob' => [],
		]);
		$this->assertSame(['carol'], $this->service->getPeers('alice'));
	}

	public function testEmployeeWithoutAManagerHasNoPeers(): void {
		$this->directory(['alice' => [], 'carol' => []]);
		$this->assertSame([], $this->service->getPeers('alice'));
	}

	public function testTheReportsIndexIsBuiltOnlyOncePerRequest(): void {
		// Building it walks every user in the instance, so repeating that per
		// permission check would be quadratic on a large directory.
		$this->directory(['alice' => ['bob'], 'bob' => []]);
		$this->userManager->expects(self::once())->method('callForAllUsers');

		$this->service->getDirectReports('bob');
		$this->service->getDirectReports('bob');
		$this->service->isManagerOf('bob', 'alice');
	}
}
