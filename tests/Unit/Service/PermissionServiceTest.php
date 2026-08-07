<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The app's authorization boundary: every controller delegates its role checks
 * here, so a wrong answer is a data leak rather than a bug. Covers who may see,
 * decide on and change a request, including the HR-recorded-leave carve-out.
 */
class PermissionServiceTest extends TestCase {
	private IGroupManager&MockObject $groupManager;
	private ManagerResolver&MockObject $managerResolver;
	private ConfigService&MockObject $config;
	private LeaveTypeMapper&MockObject $leaveTypeMapper;
	private LoggerInterface&MockObject $logger;
	private PermissionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->managerResolver = $this->createMock(ManagerResolver::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->leaveTypeMapper = $this->createMock(LeaveTypeMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->config->method('getHrGroup')->willReturn('hr');
		$this->service = new PermissionService(
			$this->groupManager,
			$this->managerResolver,
			$this->config,
			$this->leaveTypeMapper,
			$this->logger,
		);
	}

	private function request(string $employeeUid, ?string $managerUid = null, int $typeId = 1): LeaveRequest {
		$request = new LeaveRequest();
		$request->setEmployeeUid($employeeUid);
		$request->setManagerUid($managerUid);
		$request->setTypeId($typeId);
		return $request;
	}

	/** @param string[] $hrMembers */
	private function hrGroupIs(array $hrMembers): void {
		$this->groupManager->method('isInGroup')
			->willReturnCallback(static fn (string $uid, string $gid): bool => $gid === 'hr' && in_array($uid, $hrMembers, true));
	}

	private function leaveTypeIs(bool $employeeRequestable): void {
		$type = new LeaveType();
		$type->setEmployeeRequestable($employeeRequestable);
		$this->leaveTypeMapper->method('find')->willReturn($type);
	}

	// ------------------------------------------------------------- canView ----

	public function testEmployeeCanViewOwnRequest(): void {
		$this->hrGroupIs([]);
		$this->managerResolver->method('isManagerOf')->willReturn(false);
		$this->assertTrue($this->service->canView('alice', $this->request('alice')));
	}

	public function testUnrelatedColleagueCannotView(): void {
		$this->hrGroupIs([]);
		$this->managerResolver->method('isManagerOf')->willReturn(false);
		$this->assertFalse($this->service->canView('mallory', $this->request('alice')));
	}

	public function testManagerCanViewReportsRequest(): void {
		$this->hrGroupIs([]);
		$this->managerResolver->method('isManagerOf')
			->willReturnCallback(static fn (string $a, string $e): bool => $a === 'bob' && $e === 'alice');
		$this->assertTrue($this->service->canView('bob', $this->request('alice')));
	}

	public function testHrCanViewAnyRequest(): void {
		$this->hrGroupIs(['hilda']);
		$this->managerResolver->method('isManagerOf')->willReturn(false);
		$this->assertTrue($this->service->canView('hilda', $this->request('alice')));
	}

	// ----------------------------------------------------------- canDecide ----

	public function testEmployeeCannotDecideOwnRequestEvenAsHr(): void {
		// The self-approval bar outranks the HR override, or an HR member could
		// approve their own leave with nobody in the loop.
		$this->hrGroupIs(['alice']);
		$this->assertFalse($this->service->canDecide('alice', $this->request('alice')));
	}

	public function testAssignedManagerCanDecide(): void {
		$this->hrGroupIs([]);
		$this->managerResolver->method('isManagerOf')->willReturn(false);
		$this->assertTrue($this->service->canDecide('bob', $this->request('alice', 'bob')));
	}

	public function testManagerOfEmployeeCanDecideEvenIfNotTheAssignedOne(): void {
		// Covers a re-org: the request still names the old manager.
		$this->hrGroupIs([]);
		$this->managerResolver->method('isManagerOf')
			->willReturnCallback(static fn (string $a, string $e): bool => $a === 'carol' && $e === 'alice');
		$this->assertTrue($this->service->canDecide('carol', $this->request('alice', 'bob')));
	}

	public function testUnrelatedColleagueCannotDecide(): void {
		$this->hrGroupIs([]);
		$this->managerResolver->method('isManagerOf')->willReturn(false);
		$this->assertFalse($this->service->canDecide('mallory', $this->request('alice', 'bob')));
	}

	public function testHrCanDecideSomeoneElsesRequest(): void {
		$this->hrGroupIs(['hilda']);
		$this->assertTrue($this->service->canDecide('hilda', $this->request('alice', 'bob')));
	}

	// ----------------------------------------------------------- canModify ----

	public function testEmployeeCanModifyOwnSelfRequestableLeave(): void {
		$this->hrGroupIs([]);
		$this->leaveTypeIs(true);
		$this->assertTrue($this->service->canModify('alice', $this->request('alice')));
	}

	public function testEmployeeCannotModifyOwnHrRecordedLeave(): void {
		// Sick leave is recorded by HR; the employee must not be able to edit or
		// cancel their own record (§5.6).
		$this->hrGroupIs([]);
		$this->leaveTypeIs(false);
		$this->assertFalse($this->service->canModify('alice', $this->request('alice')));
	}

	public function testHrCanModifyHrRecordedLeave(): void {
		$this->hrGroupIs(['hilda']);
		$this->leaveTypeIs(false);
		$this->assertTrue($this->service->canModify('hilda', $this->request('alice')));
	}

	public function testManagerCannotModifyReportsRequest(): void {
		// Deciding is not editing: a manager approves or rejects, but does not
		// rewrite what the employee asked for.
		$this->hrGroupIs([]);
		$this->managerResolver->method('isManagerOf')->willReturn(true);
		$this->leaveTypeIs(true);
		$this->assertFalse($this->service->canModify('bob', $this->request('alice', 'bob')));
	}

	public function testMissingLeaveTypeFallsBackToAllowingTheOwner(): void {
		// A deleted type must not strand the owner with an uneditable request.
		$this->hrGroupIs([]);
		$this->leaveTypeMapper->method('find')->willThrowException(new DoesNotExistException(''));
		$this->assertTrue($this->service->canModify('alice', $this->request('alice')));
	}

	// ------------------------------------------------ balances and assertHr ----

	public function testCanViewOwnBalanceButNotAStrangersBalance(): void {
		$this->hrGroupIs([]);
		$this->managerResolver->method('isManagerOf')->willReturn(false);
		$this->assertTrue($this->service->canViewBalanceOf('alice', 'alice'));
		$this->assertFalse($this->service->canViewBalanceOf('mallory', 'alice'));
	}

	public function testAssertHrThrowsForNonHr(): void {
		$this->hrGroupIs([]);
		$this->expectException(ForbiddenException::class);
		$this->service->assertHr('alice');
	}

	public function testAssertHrPassesForHr(): void {
		$this->hrGroupIs(['hilda']);
		$this->service->assertHr('hilda');
		$this->addToAssertionCount(1);
	}

	// ---------------------------------------------------------- getHrUids ----

	public function testGetHrUidsReturnsMembers(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$this->user('hilda'), $this->user('hank')]);
		$this->groupManager->method('get')->with('hr')->willReturn($group);
		$this->assertSame(['hilda', 'hank'], $this->service->getHrUids());
	}

	public function testMissingHrGroupIsLoggedAsAnError(): void {
		// Escalation is the last resort for a request nobody else can decide, so
		// silently returning "no recipients" would strand it invisibly.
		$this->groupManager->method('get')->with('hr')->willReturn(null);
		$this->logger->expects($this->once())->method('error');
		$this->assertSame([], $this->service->getHrUids());
	}

	public function testEmptyHrGroupIsLoggedAsAnError(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([]);
		$this->groupManager->method('get')->with('hr')->willReturn($group);
		$this->logger->expects($this->once())->method('error');
		$this->assertSame([], $this->service->getHrUids());
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}
}
