<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The app's definition of "employee". Everything that lists people goes through
 * here, so a wrong answer either hands a guest an entitlement they can never use
 * or hides a real colleague from the balances report.
 */
class EmployeeDirectoryTest extends TestCase {
	private IUserManager&MockObject $userManager;
	private IGroupManager&MockObject $groupManager;
	private LoggerInterface&MockObject $logger;
	/** Employees group the config reports; '' = every non-guest account. */
	private string $employeesGroup = '';
	private EmployeeDirectory $directory;

	protected function setUp(): void {
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$config = $this->createMock(ConfigService::class);
		$config->method('getEmployeesGroup')->willReturnCallback(fn (): string => $this->employeesGroup);
		$this->directory = new EmployeeDirectory($this->userManager, $this->groupManager, $config, $this->logger);
	}

	/**
	 * Wire up an instance: uid => backend name. The Guests app reports 'Guests'
	 * for accounts in its own user backend; everything else is a real account.
	 *
	 * @param array<string,string> $backends
	 * @return array<string,IUser&MockObject>
	 */
	private function instance(array $backends): array {
		$users = [];
		foreach ($backends as $uid => $backend) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getBackendClassName')->willReturn($backend);
			$users[$uid] = $user;
		}
		$this->userManager->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => $users[$uid] ?? null,
		);
		$this->userManager->method('callForAllUsers')->willReturnCallback(
			static function (callable $fn) use ($users): void {
				foreach ($users as $user) {
					$fn($user);
				}
			},
		);
		return $users;
	}

	/**
	 * Declare the groups of the instance: group id => member uids (subset of the
	 * users wired up via {@see instance()}).
	 *
	 * @param array<string,IUser&MockObject> $users
	 * @param array<string,string[]> $groups
	 */
	private function instanceGroups(array $users, array $groups): void {
		$resolved = [];
		foreach ($groups as $gid => $memberUids) {
			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn(
				array_values(array_intersect_key($users, array_fill_keys($memberUids, true))),
			);
			$resolved[$gid] = $group;
		}
		$this->groupManager->method('get')->willReturnCallback(
			static fn (string $gid): ?IGroup => $resolved[$gid] ?? null,
		);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => in_array($uid, $groups[$gid] ?? [], true),
		);
	}

	public function testListAllOmitsGuests(): void {
		$this->instance(['alice' => 'Database', 'ext' => 'Guests', 'bob' => 'LDAP']);

		self::assertSame(['alice', 'bob'], $this->directory->listAll());
	}

	public function testAnInstanceWithoutTheGuestsAppIsUnaffected(): void {
		$this->instance(['alice' => 'Database', 'bob' => 'Database']);

		self::assertSame(['alice', 'bob'], $this->directory->listAll());
	}

	public function testIsEmployeeDistinguishesGuestsFromColleagues(): void {
		$this->instance(['alice' => 'Database', 'ext' => 'Guests']);

		self::assertTrue($this->directory->isEmployee('alice'));
		self::assertFalse($this->directory->isEmployee('ext'));
	}

	public function testAnUnknownUidIsNeitherEmployeeNorGuest(): void {
		$this->instance(['alice' => 'Database']);

		// Not an employee, but also not a guest: the uid simply does not resolve,
		// and callers use these two questions for different decisions.
		self::assertFalse($this->directory->isEmployee('ghost'));
		self::assertFalse($this->directory->isGuest('ghost'));
	}

	public function testGroupMembersAreFilteredToo(): void {
		$users = $this->instance(['alice' => 'Database', 'ext' => 'Guests']);
		$this->instanceGroups($users, ['staff' => ['alice', 'ext']]);

		self::assertSame(['alice'], $this->directory->listInGroup('staff'));
	}

	public function testAMissingGroupYieldsNobodyRatherThanEveryone(): void {
		$this->instance(['alice' => 'Database']);
		$this->instanceGroups([], []);

		// Widening to the whole company on a stale group name would quietly leak a
		// company-wide report to someone who asked for one team.
		self::assertSame([], $this->directory->listInGroup('gone'));
	}

	public function testNoGroupMeansEveryEmployee(): void {
		$this->instance(['alice' => 'Database', 'ext' => 'Guests']);

		self::assertSame(['alice'], $this->directory->listInGroup(null));
		self::assertSame(['alice'], $this->directory->listInGroup(''));
	}

	public function testFilterKeepsOrderAndDropsGuestsAndUnknowns(): void {
		$this->instance(['alice' => 'Database', 'ext' => 'Guests', 'bob' => 'Database']);

		self::assertSame(['bob', 'alice'], $this->directory->filter(['bob', 'ext', 'alice', 'ghost']));
	}

	public function testAConfiguredEmployeesGroupNarrowsWhoCounts(): void {
		$users = $this->instance(['alice' => 'Database', 'bob' => 'Database', 'svc' => 'Database']);
		$this->instanceGroups($users, ['staff' => ['alice', 'bob']]);
		$this->employeesGroup = 'staff';

		// The service account exists but is not staff: no leave, no reports, no pickers.
		self::assertSame(['alice', 'bob'], $this->directory->listAll());
		self::assertTrue($this->directory->isEmployee('alice'));
		self::assertFalse($this->directory->isEmployee('svc'));
		self::assertSame(['alice'], $this->directory->filter(['alice', 'svc']));
	}

	public function testAGuestInsideTheEmployeesGroupIsStillNoEmployee(): void {
		$users = $this->instance(['alice' => 'Database', 'ext' => 'Guests']);
		$this->instanceGroups($users, ['staff' => ['alice', 'ext']]);
		$this->employeesGroup = 'staff';

		self::assertSame(['alice'], $this->directory->listAll());
		self::assertFalse($this->directory->isEmployee('ext'));
	}

	public function testADeletedEmployeesGroupFailsOpenWithALoudError(): void {
		$this->instance(['alice' => 'Database', 'bob' => 'Database']);
		$this->instanceGroups([], []); // the configured group no longer exists
		$this->employeesGroup = 'gone';

		// Failing closed would freeze leave booking for the whole company over a
		// deleted group; failing open only widens who counts, and the admin is told.
		$this->logger->expects(self::once())->method('error');
		self::assertSame(['alice', 'bob'], $this->directory->listAll());
		self::assertTrue($this->directory->isEmployee('alice'));
	}
}
