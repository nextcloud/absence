<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Db\Entitlement;
use OCA\Absence\Db\EntitlementMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCP\IDBConnection;
use OCP\L10N\IFactory;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The carried-over days a balance says are about to expire (§6.2), counted on
 * a real database: 5 days carried into 2032, expiring on 2032-03-31. The same
 * leave as CarryOverTest::testOnlyTheUnusedCarryOverExpiresAndOnlyOnce(), which
 * keeps 2 of the 5 days.
 */
class CarryOverExpiringTest extends TestCase {
	private const ROBIN = 'itest-expiring-robin';
	private const KIM = 'itest-expiring-kim';

	private int $annual = 0;

	protected function setUp(): void {
		parent::setUp();
		foreach (Server::get(LeaveTypeMapper::class)->findAll() as $type) {
			if ($type->getKey() === 'annual') {
				$this->annual = (int)$type->getId();
			}
		}
	}

	protected function tearDown(): void {
		$db = Server::get(IDBConnection::class);
		foreach (['absence_requests', 'absence_entitlements'] as $table) {
			foreach ([self::ROBIN, self::KIM] as $uid) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($uid)))->executeStatement();
			}
		}
		parent::tearDown();
	}

	private function balances(): BalanceService {
		$config = $this->createMock(ConfigService::class);
		$config->method('getCarryOverExpiry')->willReturn('03-31');
		$clock = $this->createMock(ClockService::class);
		$clock->method('serverToday')->willReturn('2032-03-10');
		$clock->method('serverYear')->willReturn(2032);
		$clock->method('userYear')->willReturn(2032);
		return new BalanceService(
			Server::get(LeaveRequestMapper::class),
			Server::get(EntitlementMapper::class),
			Server::get(LeaveTypeMapper::class),
			$config,
			$clock,
			Server::get(IFactory::class)->get(ConfigService::APP_ID),
		);
	}

	private function leave(string $uid, string $status, string $start, float $days): void {
		$request = new LeaveRequest();
		$request->setEmployeeUid($uid);
		$request->setTypeId($this->annual);
		$request->setStartDate($start);
		$request->setEndDate($start);
		$request->setWorkingDays($days);
		$request->setStatus($status);
		$request->setCreatedAt(new \DateTime('2032-01-02 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2032-01-02 09:00:00'));
		Server::get(LeaveRequestMapper::class)->insert($request);
	}

	private function carried(string $uid, float $carry): void {
		$ent = new Entitlement();
		$ent->setEmployeeUid($uid);
		$ent->setYear(2032);
		$ent->setTypeId($this->annual);
		$ent->setBaseDays(28.0);
		$ent->setCarryOverDays($carry);
		$ent->setManualAdjustment(0.0);
		$ent->setCreatedAt(new \DateTime('2032-01-01 09:00:00'));
		$ent->setUpdatedAt(new \DateTime('2032-01-01 09:00:00'));
		Server::get(EntitlementMapper::class)->insert($ent);
	}

	/** @param list<array<string,mixed>> $rows */
	private function annualRow(array $rows): array {
		foreach ($rows as $row) {
			if ($row['typeId'] === $this->annual && $row['year'] === 2032) {
				return $row;
			}
		}
		self::fail('no annual row');
	}

	public function testOnlyTakenLeaveStartingByTheExpiryDateUsesCarryOver(): void {
		$this->carried(self::ROBIN, 5.0);
		$this->leave(self::ROBIN, LeaveRequest::STATUS_APPROVED, '2032-03-31', 2.0);
		// neither leave that is not approved yet nor leave after the date uses carry-over
		$this->leave(self::ROBIN, LeaveRequest::STATUS_PENDING, '2032-03-01', 1.0);
		$this->leave(self::ROBIN, LeaveRequest::STATUS_APPROVED, '2032-04-01', 3.0);
		// somebody else's leave is not Robin's
		$this->carried(self::KIM, 4.0);
		$this->leave(self::KIM, LeaveRequest::STATUS_APPROVED, '2032-02-02', 4.0);

		$single = $this->annualRow($this->balances()->getBalance(self::ROBIN, 2032)['balances']);
		self::assertSame([3.0, '2032-03-31'], [$single['carryOverExpiring'], $single['carryOverExpiryDate']]);

		// the batch path says the same
		$batch = $this->balances()->getBalancesForEmployees([self::ROBIN, self::KIM], 2032);
		self::assertSame(3.0, $this->annualRow($batch[self::ROBIN])['carryOverExpiring']);
		$kim = $this->annualRow($batch[self::KIM]);
		self::assertSame([0.0, null], [$kim['carryOverExpiring'], $kim['carryOverExpiryDate']]);
	}
}
