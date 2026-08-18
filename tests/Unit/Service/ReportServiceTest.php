<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Service\ReportService;
use OCA\Absence\Tests\Unit\L10nMockTrait;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReportServiceTest extends TestCase {
	use L10nMockTrait;
	private BalanceService&MockObject $balanceService;
	private LeaveRequestMapper&MockObject $requestMapper;
	private LeaveTypeMapper&MockObject $leaveTypeMapper;
	private IUserManager&MockObject $userManager;
	private IGroupManager&MockObject $groupManager;
	private ManagerResolver&MockObject $managerResolver;
	private ReportService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->balanceService = $this->createMock(BalanceService::class);
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);
		$this->leaveTypeMapper = $this->createMock(LeaveTypeMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->managerResolver = $this->createMock(ManagerResolver::class);
		$this->service = new ReportService(
			$this->balanceService,
			$this->requestMapper,
			$this->leaveTypeMapper,
			new EmployeeDirectory(
				$this->userManager,
				$this->groupManager,
				$this->createMock(ConfigService::class),
				$this->createMock(\Psr\Log\LoggerInterface::class),
			),
			$this->managerResolver,
			$this->userManager,
			$this->l10nMock(),
		);
	}

	/** @param string[] $uids */
	private function groupContains(string $groupId, array $uids): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn(array_map(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
			return $user;
		}, $uids));
		$this->groupManager->method('get')->with($groupId)->willReturn($group);
		$this->userManager->method('get')->willReturnCallback(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
			return $user;
		});
	}

	private function sickType(int $id = 3): LeaveType {
		$type = new LeaveType();
		$type->setId($id);
		$type->setKey('sick');
		$type->setLabel('Sick leave');
		$type->setColor('#cc0000');
		$type->setIcon('');
		$type->setEnabled(true);
		return $type;
	}

	private function request(string $uid, int $typeId, float $days, string $start, string $end): LeaveRequest {
		$request = new LeaveRequest();
		$request->setEmployeeUid($uid);
		$request->setTypeId($typeId);
		$request->setWorkingDays($days);
		$request->setStartDate($start);
		$request->setEndDate($end);
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		return $request;
	}

	// ----------------------------------------------------- balances report ----

	public function testBalancesReportFetchesEveryEmployeeInOneBatch(): void {
		// Guards the fix for the N+1 that made this report unusable at scale: one
		// batched call, not one getBalance() per head.
		$this->groupContains('team', ['alice', 'bob']);
		$this->balanceService->expects(self::once())
			->method('getBalancesForEmployees')
			->with(['alice', 'bob'], 2026)
			->willReturn([
				'alice' => [['typeId' => 1, 'sortOrder' => 1, 'used' => 3.0]],
				'bob' => [['typeId' => 1, 'sortOrder' => 1, 'used' => 1.0]],
			]);
		$this->balanceService->expects(self::never())->method('getBalance');

		$report = $this->service->balancesReport(2026, 'team');

		$this->assertCount(2, $report);
		$this->assertSame(['Alice', 'Bob'], array_column($report, 'displayName'));
		$this->assertSame(['alice', 'bob'], array_column($report, 'employeeUid'));
	}

	public function testBalancesReportSkipsEmployeesWithNoRows(): void {
		$this->groupContains('team', ['alice', 'bob']);
		$this->balanceService->method('getBalancesForEmployees')->willReturn([
			'alice' => [['typeId' => 1, 'sortOrder' => 1]],
		]);

		$report = $this->service->balancesReport(2026, 'team');

		$this->assertCount(1, $report);
		$this->assertSame('alice', $report[0]['employeeUid']);
	}

	public function testUnknownGroupYieldsAnEmptyReport(): void {
		$this->groupManager->method('get')->with('ghosts')->willReturn(null);
		$this->balanceService->method('getBalancesForEmployees')->willReturn([]);
		$this->assertSame([], $this->service->balancesReport(2026, 'ghosts'));
	}

	// --------------------------------------------------- sick leave report ----

	public function testSickLeaveAggregatesDaysEpisodesAndLongest(): void {
		$this->groupContains('team', ['alice', 'bob']);
		$this->leaveTypeMapper->method('findAll')->willReturn([$this->sickType()]);
		$this->requestMapper->method('findForEmployeesInRange')->willReturn([
			$this->request('alice', 3, 2.0, '2026-01-05', '2026-01-06'),
			$this->request('alice', 3, 5.0, '2026-03-02', '2026-03-06'),
			$this->request('bob', 3, 1.0, '2026-02-02', '2026-02-02'),
		]);

		$report = $this->service->sickLeaveReport(2026, 'team');

		$this->assertSame(8.0, $report['totals']['days']);
		$this->assertSame(2, $report['totals']['affected']);
		$this->assertSame(2, $report['totals']['employees']);
		// Ranked by days lost, so Alice leads.
		$this->assertSame('alice', $report['rows'][0]['employeeUid']);
		$this->assertSame(7.0, $report['rows'][0]['days']);
		$this->assertSame(2, $report['rows'][0]['episodes']);
		$this->assertSame(5.0, $report['rows'][0]['longestEpisode']);
		$this->assertSame('2026-03-06', $report['rows'][0]['lastDate']);
	}

	public function testSickLeaveIgnoresOtherLeaveTypes(): void {
		$this->groupContains('team', ['alice']);
		$this->leaveTypeMapper->method('findAll')->willReturn([$this->sickType()]);
		$this->requestMapper->method('findForEmployeesInRange')->willReturn([
			$this->request('alice', 3, 2.0, '2026-01-05', '2026-01-06'),
			// annual leave: not sickness, must not appear in a health report
			$this->request('alice', 1, 10.0, '2026-07-01', '2026-07-10'),
		]);

		$report = $this->service->sickLeaveReport(2026, 'team');

		$this->assertSame(2.0, $report['totals']['days']);
		$this->assertSame(1, $report['rows'][0]['episodes']);
	}

	public function testSickLeaveExcludesLeaveStartingInAnotherYear(): void {
		// The range query also returns leave merely overlapping the year; days are
		// attributed to the year the leave starts, matching how balances count.
		$this->groupContains('team', ['alice']);
		$this->leaveTypeMapper->method('findAll')->willReturn([$this->sickType()]);
		$this->requestMapper->method('findForEmployeesInRange')->willReturn([
			$this->request('alice', 3, 4.0, '2025-12-29', '2026-01-02'),
		]);

		$report = $this->service->sickLeaveReport(2026, 'team');

		$this->assertSame(0.0, $report['totals']['days']);
		$this->assertSame(0, $report['totals']['affected']);
	}

	public function testNoSickLeaveTypeConfiguredReportsNoTypes(): void {
		// The UI uses an empty `types` to say so rather than showing zeroes.
		$this->groupContains('team', ['alice']);
		$this->leaveTypeMapper->method('findAll')->willReturn([]);
		$this->requestMapper->expects(self::never())->method('findForEmployeesInRange');

		$report = $this->service->sickLeaveReport(2026, 'team');

		$this->assertSame([], $report['types']);
		$this->assertSame(0.0, $report['totals']['days']);
	}

	public function testTeamBalancesAreScopedToTheDirectReports(): void {
		$this->managerResolver->method('getDirectReports')->with('mgr')->willReturn(['bob', 'alice']);
		$this->userManager->method('get')->willReturnCallback(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
			return $user;
		});
		$this->balanceService->expects(self::once())->method('getBalancesForEmployees')
			->with(['bob', 'alice'], 2026)
			->willReturn([
				'alice' => [['typeId' => 1, 'sortOrder' => 0, 'available' => 5.0]],
				'bob' => [['typeId' => 1, 'sortOrder' => 0, 'available' => 2.0]],
			]);

		$rows = $this->service->teamBalances('mgr', 2026);

		self::assertCount(2, $rows);
		// Sorted by display name, each row naming its employee.
		self::assertSame(['Alice', 'Bob'], array_column($rows, 'displayName'));
		self::assertSame(['alice', 'bob'], array_column($rows, 'employeeUid'));
	}

	public function testTeamBalancesOfANonManagerAreEmpty(): void {
		$this->managerResolver->method('getDirectReports')->willReturn([]);
		$this->balanceService->method('getBalancesForEmployees')->willReturn([]);

		self::assertSame([], $this->service->teamBalances('emp', 2026));
	}
}
