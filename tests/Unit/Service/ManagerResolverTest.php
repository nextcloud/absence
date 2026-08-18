<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\ManagerResolver;
use OCP\Config\IUserConfig;
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
	private IUserConfig&MockObject $userConfig;
	private LoggerInterface&MockObject $logger;
	private ManagerResolver $service;

	protected function setUp(): void {
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userConfig = $this->createMock(IUserConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new ManagerResolver(
			$this->userManager,
			$this->userConfig,
			new EmployeeDirectory(
				$this->userManager,
				$this->createMock(IGroupManager::class),
				$this->createMock(ConfigService::class),
				$this->createMock(LoggerInterface::class),
			),
			$this->logger,
		);
	}

	/**
	 * Wire up a directory: uid => list of configured manager uids. Users exist
	 * both as accounts and as the stored `settings/manager` preference the
	 * reverse lookup reads (as JSON, the way the server stores it).
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
		$stored = [];
		foreach ($directory as $uid => $managerUids) {
			if ($managerUids !== []) {
				$stored[$uid] = json_encode($managerUids);
			}
		}
		$this->userConfig->method('getValuesByUsers')
			->with('settings', 'manager')
			->willReturn($stored);
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

	public function testDirectReportsHonourTheFirstValidManagerRule(): void {
		// carol lists bob only *after* a valid manager, so her effective manager
		// is alice — bob must not see her as a report even though his uid appears
		// in her configured list.
		$this->directory([
			'carol' => ['alice', 'bob'],
			'alice' => [],
			'bob' => [],
		]);

		$this->assertSame([], $this->service->getDirectReports('bob'));
		$this->assertSame(['carol'], $this->service->getDirectReports('alice'));
		$this->assertFalse($this->service->isManagerOf('bob', 'carol'));
		$this->assertTrue($this->service->isManagerOf('alice', 'carol'));
	}

	public function testADepartedReportIsNotListed(): void {
		// The preference row can outlive the account (or belong to a guest):
		// somebody who no longer resolves to an employee is nobody's report.
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$this->userManager->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => $uid === 'bob' ? $bob : null,
		);
		$this->userConfig->method('getValuesByUsers')->willReturn(['ghost' => json_encode(['bob'])]);

		$this->assertSame([], $this->service->getDirectReports('bob'));
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

	public function testReverseLookupNeverEnumeratesTheUserBackend(): void {
		// The whole point of the preference-dump lookup: direct reports come from
		// one indexed query, fetched once per request — never from walking every
		// account, which made each permission check O(headcount).
		$this->directory(['alice' => ['bob'], 'bob' => []]);
		$this->userManager->expects(self::never())->method('callForAllUsers');

		$this->service->getDirectReports('bob');
		$this->service->getDirectReports('bob');
		$this->service->isManagerOf('bob', 'alice');
	}

	public function testThePreferenceDumpIsFetchedOnlyOncePerRequest(): void {
		$this->userManager->method('get')->willReturnCallback(function (string $uid): ?IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getManagerUids')->willReturn([]);
			return $user;
		});
		$this->userConfig->expects(self::once())->method('getValuesByUsers')
			->with('settings', 'manager')
			->willReturn(['alice' => json_encode(['bob'])]);

		$this->service->getDirectReports('bob');
		$this->service->getDirectReports('carol');
		$this->service->getPeers('alice');
	}
}
