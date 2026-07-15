<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\Entitlement;
use OCA\Absence\Db\EntitlementMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\ActivityPublisher;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EntitlementService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EntitlementServiceTest extends TestCase {
	private EntitlementMapper&MockObject $entitlementMapper;
	private BalanceService&MockObject $balanceService;
	private ConfigService&MockObject $config;
	private EntitlementService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->entitlementMapper = $this->createMock(EntitlementMapper::class);
		$this->balanceService = $this->createMock(BalanceService::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->service = new EntitlementService(
			$this->entitlementMapper,
			$this->createMock(LeaveTypeMapper::class),
			$this->balanceService,
			$this->config,
			$this->createMock(ActivityPublisher::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function priorEntitlement(float $baseDays): Entitlement {
		$prior = new Entitlement();
		$prior->setId(1);
		$prior->setEmployeeUid('bob');
		$prior->setYear(2026);
		$prior->setTypeId(1);
		$prior->setBaseDays($baseDays);
		return $prior;
	}

	public function testRolloverCarriesPriorBaseNotGlobalDefault(): void {
		$this->config->method('getCarryOverPolicy')->willReturn(ConfigService::CARRYOVER_UNLIMITED);
		$this->config->expects($this->never())->method('getDefaultEntitlement');
		$this->entitlementMapper->method('findForYear')->with(2026)->willReturn([$this->priorEntitlement(5.0)]);
		$this->entitlementMapper->method('findFor')->with('bob', 2027, 1)->willThrowException(new DoesNotExistException(''));
		$this->balanceService->method('getBalance')->with('bob', 2026)
			->willReturn(['balances' => [['typeId' => 1, 'remaining' => 2.0]]]);
		$this->balanceService->expects($this->never())->method('ensureEntitlement');
		$this->entitlementMapper->method('insert')->willReturnArgument(0);
		$updated = null;
		$this->entitlementMapper->method('update')->willReturnCallback(static function (Entitlement $e) use (&$updated) {
			$updated = $e;
			return $e;
		});

		$this->assertSame(1, $this->service->rollover(2026));

		$this->assertNotNull($updated);
		// HR granted 5 days in 2026; 2027 must continue that base, not the 25-day default.
		$this->assertSame(5.0, $updated->getBaseDays());
		$this->assertSame(2.0, $updated->getCarryOverDays());
		$this->assertSame(2027, $updated->getYear());
	}

	public function testRolloverKeepsExistingNextYearBase(): void {
		$this->config->method('getCarryOverPolicy')->willReturn(ConfigService::CARRYOVER_UNLIMITED);
		$this->entitlementMapper->method('findForYear')->with(2026)->willReturn([$this->priorEntitlement(5.0)]);
		$existing = new Entitlement();
		$existing->setId(2);
		$existing->setEmployeeUid('bob');
		$existing->setYear(2027);
		$existing->setTypeId(1);
		$existing->setBaseDays(12.0);
		$this->entitlementMapper->method('findFor')->with('bob', 2027, 1)->willReturn($existing);
		$this->balanceService->method('getBalance')->with('bob', 2026)
			->willReturn(['balances' => [['typeId' => 1, 'remaining' => 2.0]]]);
		$this->entitlementMapper->expects($this->never())->method('insert');
		$updated = null;
		$this->entitlementMapper->method('update')->willReturnCallback(static function (Entitlement $e) use (&$updated) {
			$updated = $e;
			return $e;
		});

		$this->service->rollover(2026);

		$this->assertNotNull($updated);
		// A base HR already set for the new year is never touched; only carry-over is.
		$this->assertSame(12.0, $updated->getBaseDays());
		$this->assertSame(2.0, $updated->getCarryOverDays());
	}

	public function testRolloverWithCappedPolicyCapsCarryOver(): void {
		$this->config->method('getCarryOverPolicy')->willReturn(ConfigService::CARRYOVER_CAPPED);
		$this->config->method('getCarryOverCap')->willReturn(10.0);
		$this->entitlementMapper->method('findForYear')->with(2026)->willReturn([$this->priorEntitlement(30.0)]);
		$this->entitlementMapper->method('findFor')->with('bob', 2027, 1)->willThrowException(new DoesNotExistException(''));
		$this->balanceService->method('getBalance')->with('bob', 2026)
			->willReturn(['balances' => [['typeId' => 1, 'remaining' => 14.0]]]);
		$this->entitlementMapper->method('insert')->willReturnArgument(0);
		$updated = null;
		$this->entitlementMapper->method('update')->willReturnCallback(static function (Entitlement $e) use (&$updated) {
			$updated = $e;
			return $e;
		});

		$this->service->rollover(2026);

		$this->assertNotNull($updated);
		$this->assertSame(30.0, $updated->getBaseDays());
		$this->assertSame(10.0, $updated->getCarryOverDays());
	}
}
