<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\Entitlement;
use OCA\Absence\Db\EntitlementMapper;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The carried-over days a balance row says are about to expire (§6.2): Robin
 * carried 5 days into 2032, and they expire on 2032-03-31.
 */
class BalanceCarryOverExpiryTest extends TestCase {
	private LeaveRequestMapper&MockObject $requestMapper;
	private EntitlementMapper&MockObject $entitlementMapper;
	private string $expiry = '03-31';
	private string $today = '2032-03-01';
	/** @var array<string,array<int,float>> taken leave starting by the expiry date */
	private array $usedByExpiry = [];
	/** @var list<array{string,string,?string}> the usage queries made */
	private array $usageQueries = [];
	private BalanceService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);
		$this->requestMapper->method('sumUsedDaysStartingBetween')->willReturnCallback(
			function (string $from, string $to, ?string $uid = null): array {
				$this->usageQueries[] = [$from, $to, $uid];
				return $this->usedByExpiry;
			},
		);
		$this->entitlementMapper = $this->createMock(EntitlementMapper::class);
		$type = new LeaveType();
		$type->setId(1);
		$type->setKey('annual');
		$type->setCountsAgainstBalance(true);
		$typeMapper = $this->createMock(LeaveTypeMapper::class);
		$typeMapper->method('findAll')->willReturn([$type]);
		$config = $this->createMock(ConfigService::class);
		$config->method('getCarryOverExpiry')->willReturnCallback(fn (): string => $this->expiry);
		$clock = $this->createMock(ClockService::class);
		$clock->method('serverToday')->willReturnCallback(fn (): string => $this->today);
		$clock->method('serverYear')->willReturnCallback(fn (): int => (int)substr($this->today, 0, 4));
		$clock->method('userYear')->willReturnCallback(fn (): int => (int)substr($this->today, 0, 4));
		$this->service = new BalanceService(
			$this->requestMapper,
			$this->entitlementMapper,
			$typeMapper,
			$config,
			$clock,
			$this->createMock(IL10N::class),
		);
	}

	private function entitlement(string $uid, float $carry, int $year = 2032): Entitlement {
		$ent = new Entitlement();
		$ent->setId(7);
		$ent->setEmployeeUid($uid);
		$ent->setYear($year);
		$ent->setTypeId(1);
		$ent->setBaseDays(28.0);
		$ent->setCarryOverDays($carry);
		return $ent;
	}

	/** @return array{float,?string} what Robin's row says expires, and when */
	private function expiring(float $carry = 5.0, int $year = 2032): array {
		$this->entitlementMapper->method('findForEmployee')->willReturn([$this->entitlement('robin', $carry, $year)]);
		$row = $this->service->getBalance('robin', $year)['balances'][0];
		return [$row['carryOverExpiring'], $row['carryOverExpiryDate']];
	}

	public function testUnusedCarryOverExpiresInFull(): void {
		self::assertSame([5.0, '2032-03-31'], $this->expiring());
		// one query, for this employee only, over the same dates the expiry uses
		self::assertSame([['2032-01-01', '2032-03-31', 'robin']], $this->usageQueries);
	}

	public function testPartlyUsedCarryOverLosesTheRest(): void {
		$this->usedByExpiry = ['robin' => [1 => 1.5]];
		self::assertSame([3.5, '2032-03-31'], $this->expiring());
	}

	public function testCarryOverCoveredByTakenLeaveLosesNothing(): void {
		$this->usedByExpiry = ['robin' => [1 => 8.0]];
		self::assertSame([0.0, null], $this->expiring());
	}

	public function testNoExpirySetWarnsAboutNothing(): void {
		$this->expiry = '';
		self::assertSame([0.0, null], $this->expiring());
		self::assertSame([], $this->usageQueries);
	}

	public function testNothingCarriedOverWarnsAboutNothing(): void {
		self::assertSame([0.0, null], $this->expiring(0.0));
		self::assertSame([], $this->usageQueries);
	}

	public function testOnTheExpiryDateItStillWarns(): void {
		$this->today = '2032-03-31';
		self::assertSame([5.0, '2032-03-31'], $this->expiring());
	}

	public function testAfterTheExpiryDateItWarnsAboutNothing(): void {
		// even when the job that removes the days has not run yet
		$this->today = '2032-04-01';
		self::assertSame([0.0, null], $this->expiring());
		self::assertSame([], $this->usageQueries);
	}

	public function testAnotherYearIsNotWarnedAbout(): void {
		self::assertSame([0.0, null], $this->expiring(5.0, 2033));
		self::assertSame([], $this->usageQueries);
	}

	public function testManyEmployeesShareOneUsageQuery(): void {
		$this->entitlementMapper->method('findForYear')->willReturn([
			$this->entitlement('robin', 5.0),
			$this->entitlement('kim', 4.0),
			$this->entitlement('sam', 0.0),
		]);
		$this->requestMapper->method('aggregateWorkingDaysForYear')->willReturn([]);
		$this->requestMapper->method('findPendingSupersedingForYear')->willReturn([]);
		$this->usedByExpiry = ['kim' => [1 => 4.0], 'robin' => [1 => 2.0]];

		$balances = $this->service->getBalancesForEmployees(['robin', 'kim', 'sam'], 2032);

		self::assertSame(
			['robin' => 3.0, 'kim' => 0.0, 'sam' => 0.0],
			array_map(static fn (array $rows): float => $rows[0]['carryOverExpiring'], $balances),
		);
		self::assertSame([['2032-01-01', '2032-03-31', null]], $this->usageQueries);
	}
}
