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
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\InsightsService;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Tests\Unit\L10nMockTrait;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class InsightsServiceTest extends TestCase {
	use L10nMockTrait;

	private LeaveRequestMapper&MockObject $requestMapper;
	private LeaveTypeMapper&MockObject $leaveTypeMapper;
	private BalanceService&MockObject $balanceService;
	private EmployeeDirectory&MockObject $employees;
	private ManagerResolver&MockObject $managerResolver;
	private ConfigService&MockObject $config;
	private ClockService&MockObject $clock;
	private InsightsService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);
		$this->leaveTypeMapper = $this->createMock(LeaveTypeMapper::class);
		$this->balanceService = $this->createMock(BalanceService::class);
		$this->employees = $this->createMock(EmployeeDirectory::class);
		$this->managerResolver = $this->createMock(ManagerResolver::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->clock = $this->createMock(ClockService::class);
		$userManager = $this->createMock(IUserManager::class);
		// get() returns null → display names fall back to the uid, so assertions
		// can key on the uid without standing up fake user objects.
		$userManager->method('get')->willReturn(null);

		$this->employees->method('listInGroup')->willReturn(['alice', 'bob', 'carol']);
		$this->managerResolver->method('getManagerUid')->willReturnCallback(
			static fn (string $uid): ?string => $uid === 'carol' ? null : 'mgr1',
		);
		$this->clock->method('userToday')->willReturn('2026-06-15');
		$this->leaveTypeMapper->method('findAll')->willReturn([
			$this->type(1, 'annual'),
			$this->type(2, 'sick'),
		]);

		$this->service = new InsightsService(
			$this->requestMapper,
			$this->leaveTypeMapper,
			$this->balanceService,
			$this->employees,
			$this->managerResolver,
			$this->config,
			$this->clock,
			$userManager,
			$this->l10nMock(),
		);
	}

	private function type(int $id, string $key): LeaveType {
		$type = new LeaveType();
		$type->setId($id);
		$type->setKey($key);
		return $type;
	}

	/**
	 * @param array<string,mixed> $props
	 */
	private function req(array $props): LeaveRequest {
		$r = new LeaveRequest();
		$r->setEmployeeUid($props['emp']);
		$r->setManagerUid($props['manager'] ?? null);
		$r->setTypeId($props['type']);
		$r->setStatus($props['status']);
		$r->setStartDate($props['start']);
		$r->setEndDate($props['end'] ?? $props['start']);
		$r->setWorkingDays($props['days'] ?? 1.0);
		$r->setEscalated($props['escalated'] ?? false);
		$r->setCreatedAt(new \DateTime($props['created'] ?? '2026-01-01 00:00:00'));
		$r->setDecidedAt(isset($props['decided']) ? new \DateTime($props['decided']) : null);
		return $r;
	}

	/**
	 * @param array<string,array{used:float,entitlement:float,remaining:float,carryOverDays:float}> $byUid
	 */
	private function balances(array $byUid): void {
		$out = [];
		foreach ($byUid as $uid => $b) {
			$out[$uid] = [[
				'typeKey' => 'annual',
				'used' => $b['used'],
				'entitlement' => $b['entitlement'],
				'remaining' => $b['remaining'],
				'carryOverDays' => $b['carryOverDays'],
			]];
		}
		$this->balanceService->method('getBalancesForEmployees')->willReturn($out);
	}

	private function withRequests(): void {
		$this->requestMapper->method('findAllInRange')->willReturn([
			// Two manager-approved annual requests with different turnarounds.
			$this->req(['emp' => 'alice', 'manager' => 'mgr1', 'type' => 1, 'status' => 'APPROVED', 'start' => '2026-03-01', 'created' => '2026-03-01 09:00:00', 'decided' => '2026-03-01 15:00:00', 'days' => 1.0]),
			$this->req(['emp' => 'bob', 'manager' => 'mgr1', 'type' => 1, 'status' => 'APPROVED', 'start' => '2026-03-02', 'end' => '2026-03-04', 'created' => '2026-03-02 09:00:00', 'decided' => '2026-03-04 09:00:00', 'escalated' => true, 'days' => 3.0]),
			// HR-recorded sick leave (no manager): counts for Bradford, not approval.
			$this->req(['emp' => 'alice', 'type' => 2, 'status' => 'APPROVED', 'start' => '2026-01-05', 'days' => 1.0]),
			$this->req(['emp' => 'alice', 'type' => 2, 'status' => 'APPROVED', 'start' => '2026-02-10', 'days' => 2.0]),
			$this->req(['emp' => 'bob', 'type' => 2, 'status' => 'APPROVED', 'start' => '2026-03-01', 'days' => 5.0]),
		]);
	}

	public function testApprovalHealthCountsOnlyManagerDecisions(): void {
		$this->withRequests();
		$this->balances(['alice' => ['used' => 5.0, 'entitlement' => 28.0, 'remaining' => 23.0, 'carryOverDays' => 3.0]]);
		$this->config->method('getCarryOverPolicy')->willReturn(ConfigService::CARRYOVER_NONE);

		$approval = $this->service->getInsights(2026)['approval'];

		self::assertSame(2, $approval['needingDecision']);
		self::assertSame(1, $approval['escalatedCount']);
		self::assertSame(0.5, $approval['escalationRate']);
		// Turnarounds are 6h and 48h → median 27.
		self::assertSame(27.0, $approval['medianHours']);
		self::assertCount(1, $approval['perManager']);
		self::assertSame('mgr1', $approval['perManager'][0]['managerUid']);
		self::assertSame(2, $approval['perManager'][0]['decided']);
		self::assertSame(1, $approval['perManager'][0]['escalated']);
		self::assertSame(27.0, $approval['perManager'][0]['medianHours']);
	}

	public function testBradfordScoresSpellsSquaredTimesDays(): void {
		$this->withRequests();
		$this->balances(['alice' => ['used' => 5.0, 'entitlement' => 28.0, 'remaining' => 23.0, 'carryOverDays' => 3.0]]);
		$this->config->method('getCarryOverPolicy')->willReturn(ConfigService::CARRYOVER_NONE);

		$bradford = $this->service->getInsights(2026)['bradford'];

		self::assertCount(2, $bradford);
		// alice: 2 spells, 3 days → 2² × 3 = 12; bob: 1 spell, 5 days → 5.
		self::assertSame('alice', $bradford[0]['employeeUid']);
		self::assertSame(2, $bradford[0]['spells']);
		self::assertSame(3.0, $bradford[0]['days']);
		self::assertSame(12, $bradford[0]['score']);
		self::assertSame('bob', $bradford[1]['employeeUid']);
		self::assertSame(5, $bradford[1]['score']);
	}

	public function testUtilizationAggregatesAndFlagsTheNeglected(): void {
		$this->withRequests();
		$this->balances([
			'alice' => ['used' => 5.0, 'entitlement' => 28.0, 'remaining' => 23.0, 'carryOverDays' => 3.0],
			'bob' => ['used' => 20.0, 'entitlement' => 28.0, 'remaining' => 8.0, 'carryOverDays' => 5.0],
			'carol' => ['used' => 0.0, 'entitlement' => 25.0, 'remaining' => 25.0, 'carryOverDays' => 0.0],
		]);
		$this->config->method('getCarryOverPolicy')->willReturn(ConfigService::CARRYOVER_NONE);

		$util = $this->service->getInsights(2026)['utilization'];

		// used 25 / entitlement 81 = 0.309.
		self::assertSame(25.0, $util['company']['used']);
		self::assertSame(81.0, $util['company']['entitlement']);
		self::assertSame(0.309, $util['company']['rate']);
		// Two teams, lowest utilisation first (carol's "no manager" team at 0%).
		self::assertSame(0.0, $util['perTeam'][0]['rate']);
		// carol never took non-sick leave in the window → top of the watchlist.
		self::assertSame('carol', $util['neglected'][0]['employeeUid']);
		self::assertNull($util['neglected'][0]['daysSince']);
		self::assertCount(3, $util['neglected']);
	}

	public function testLiabilitySumsOutstandingAndCarryOverExposure(): void {
		$this->withRequests();
		$this->balances([
			'alice' => ['used' => 5.0, 'entitlement' => 28.0, 'remaining' => 23.0, 'carryOverDays' => 3.0],
			'bob' => ['used' => 20.0, 'entitlement' => 28.0, 'remaining' => 8.0, 'carryOverDays' => 5.0],
			'carol' => ['used' => 0.0, 'entitlement' => 25.0, 'remaining' => 25.0, 'carryOverDays' => 0.0],
		]);
		$this->config->method('getCarryOverPolicy')->willReturn(ConfigService::CARRYOVER_CAPPED);
		$this->config->method('getCarryOverExpiry')->willReturn('03-31');

		$liability = $this->service->getInsights(2026)['liability'];

		// 23 + 8 + 25 = 56 outstanding; carry-over min(3,23)+min(5,8)+min(0,25) = 8.
		self::assertSame(56.0, $liability['outstanding']);
		self::assertSame(8.0, $liability['carryOverExposure']);
		// This year's carry-over expires next year on the configured MM-DD.
		self::assertSame('2027-03-31', $liability['expiryDate']);
		// Teams ranked by outstanding, largest first (mgr1: 31, no-manager: 25).
		self::assertSame(31.0, $liability['perTeam'][0]['outstanding']);
	}
}
