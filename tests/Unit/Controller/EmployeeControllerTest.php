<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Controller;

use OCA\Absence\Controller\EmployeeController;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\PermissionService;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EmployeeControllerTest extends TestCase {
	private ISearch&MockObject $collaboratorSearch;
	private IUserManager&MockObject $userManager;
	private EmployeeDirectory&MockObject $employees;
	private EmployeeController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->collaboratorSearch = $this->createMock(ISearch::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->employees = $this->createMock(EmployeeDirectory::class);
		$this->controller = new EmployeeController(
			'absence',
			$this->createMock(IRequest::class),
			'hr-user',
			$this->userManager,
			$this->createMock(IGroupManager::class),
			$this->collaboratorSearch,
			$this->employees,
			$this->createMock(PermissionService::class),
			$this->createMock(\OCP\IL10N::class),
		);
	}

	/** Core's collaborator search never returns the searching user. */
	private function collaboratorSearchReturns(array $users): void {
		$this->collaboratorSearch->method('search')->willReturn([['exact' => ['users' => []], 'users' => $users], false]);
	}

	private function selfIs(string $displayName): void {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn($displayName);
		$this->userManager->method('get')->with('hr-user')->willReturn($user);
	}

	/**
	 * The reported bug: HR could not pick themselves in "Record absence", so they
	 * could not record their own sick leave — the dialog will not submit without an
	 * employee, and sick leave is not offered by the self-service route (§5.6).
	 */
	public function testSearchIncludesTheSigningInUserWhenTheyMatch(): void {
		$this->collaboratorSearchReturns([]);
		$this->selfIs('Frank Karlitschek');
		$this->employees->method('isEmployee')->willReturn(true);

		$data = $this->controller->search('frank')->getData();

		self::assertSame([['uid' => 'hr-user', 'displayName' => 'Frank Karlitschek']], $data);
	}

	public function testSearchMatchesTheSigningInUserByUid(): void {
		$this->collaboratorSearchReturns([]);
		$this->selfIs('Frank Karlitschek');
		$this->employees->method('isEmployee')->willReturn(true);

		self::assertSame('hr-user', $this->controller->search('hr-us')->getData()[0]['uid']);
	}

	public function testSearchLeavesTheSigningInUserOutWhenTheyDoNotMatch(): void {
		$this->collaboratorSearchReturns([
			['label' => 'Lea Meyer', 'value' => ['shareWith' => 'lea']],
		]);
		$this->selfIs('Frank Karlitschek');
		$this->employees->method('isEmployee')->willReturn(true);

		$data = $this->controller->search('lea')->getData();

		self::assertSame([['uid' => 'lea', 'displayName' => 'Lea Meyer']], $data);
	}

	public function testSearchNeverOffersTheSigningInUserWhenTheyAreAGuest(): void {
		$this->collaboratorSearchReturns([]);
		$this->selfIs('Guest Person');
		// Guests hold no entitlement and take no leave, their own account included.
		$this->employees->method('isEmployee')->willReturn(false);

		self::assertSame([], $this->controller->search('guest')->getData());
	}

	public function testSearchDoesNotListTheSigningInUserTwice(): void {
		// Should core ever stop filtering self out, the dedup must still hold.
		$this->collaboratorSearchReturns([
			['label' => 'Frank Karlitschek', 'value' => ['shareWith' => 'hr-user']],
		]);
		$this->selfIs('Frank Karlitschek');
		$this->employees->method('isEmployee')->willReturn(true);

		self::assertCount(1, $this->controller->search('frank')->getData());
	}
}
