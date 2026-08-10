<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Service\EmployeeDirectory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The app's definition of "employee". Everything that lists people goes through
 * here, so a wrong answer either hands a guest an entitlement they can never use
 * or hides a real colleague from the balances report.
 */
class EmployeeDirectoryTest extends TestCase {
	private IUserManager&MockObject $userManager;
	private IGroupManager&MockObject $groupManager;
	private EmployeeDirectory $directory;

	protected function setUp(): void {
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->directory = new EmployeeDirectory($this->userManager, $this->groupManager);
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
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn(array_values($users));
		$this->groupManager->method('get')->with('staff')->willReturn($group);

		self::assertSame(['alice'], $this->directory->listInGroup('staff'));
	}

	public function testAMissingGroupYieldsNobodyRatherThanEveryone(): void {
		$this->instance(['alice' => 'Database']);
		$this->groupManager->method('get')->with('gone')->willReturn(null);

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
}
