<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\Entitlement;
use OCA\Absence\Db\EntitlementEvent;
use OCA\Absence\Db\EntitlementEventMapper;
use OCA\Absence\Db\EntitlementMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\ActivityPublisher;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\EntitlementService;
use OCA\Absence\Tests\Unit\ClockMockTrait;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EntitlementServiceTest extends TestCase {
	use ClockMockTrait;

	private EntitlementMapper&MockObject $entitlementMapper;
	private EntitlementEventMapper&MockObject $eventMapper;
	private LeaveTypeMapper&MockObject $leaveTypeMapper;
	private BalanceService&MockObject $balanceService;
	private ConfigService&MockObject $config;
	private EntitlementService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->entitlementMapper = $this->createMock(EntitlementMapper::class);
		$this->leaveTypeMapper = $this->createMock(LeaveTypeMapper::class);
		$this->balanceService = $this->createMock(BalanceService::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->eventMapper = $this->createMock(EntitlementEventMapper::class);
		$this->eventMapper->method('insert')->willReturnArgument(0);
		$this->service = new EntitlementService(
			$this->entitlementMapper,
			$this->eventMapper,
			$this->leaveTypeMapper,
			$this->balanceService,
			$this->config,
			$this->clockAtRealTime(),
			$this->createMock(ActivityPublisher::class),
			new EmployeeDirectory($this->createMock(IUserManager::class), $this->createMock(IGroupManager::class)),
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

	/**
	 * The complaint this history exists for: HR is required to write a reason when
	 * adjusting, and it used to be stored on the row and shown to nobody.
	 */
	public function testAdjustingRecordsTheChangeWithItsNote(): void {
		$ent = $this->priorEntitlement(28.0);
		$ent->setManualAdjustment(0.0);
		$this->entitlementMapper->method('find')->with(1)->willReturn($ent);
		$this->entitlementMapper->method('update')->willReturnArgument(0);

		$recorded = [];
		$this->eventMapper->method('insert')->willReturnCallback(static function (EntitlementEvent $e) use (&$recorded) {
			$recorded[] = $e;
			return $e;
		});

		$this->service->update('hr', 1, ['manualAdjustment' => 2.0, 'adjustmentNote' => 'Wedding']);

		self::assertCount(1, $recorded, 'only the figure that moved is recorded');
		self::assertSame(EntitlementEvent::FIELD_MANUAL_ADJUSTMENT, $recorded[0]->getField());
		self::assertSame(0.0, $recorded[0]->getOldValue());
		self::assertSame(2.0, $recorded[0]->getNewValue());
		self::assertSame('Wedding', $recorded[0]->getNote());
		self::assertSame('hr', $recorded[0]->getActorUid());
		self::assertSame('bob', $recorded[0]->getEmployeeUid());
	}

	public function testSavingWithoutChangingAnythingRecordsNothing(): void {
		$ent = $this->priorEntitlement(28.0);
		$this->entitlementMapper->method('find')->with(1)->willReturn($ent);
		$this->entitlementMapper->method('update')->willReturnArgument(0);

		// Re-saving the same figures is not a change and must not litter the history.
		$this->eventMapper->expects(self::never())->method('insert');

		$this->service->update('hr', 1, ['baseDays' => 28.0]);
	}

	/**
	 * Covers assertCountingType(), which setForEmployee() shares — an HR form left open
	 * while somebody else removed the type used to answer with a 500, because
	 * DoesNotExistException is not an AbsenceException and never reached the handler
	 * that turns domain errors into a 422.
	 */
	public function testBulkSetRejectsAnUnknownLeaveType(): void {
		$this->leaveTypeMapper->method('find')->with(99)
			->willThrowException(new DoesNotExistException(''));

		$this->entitlementMapper->expects(self::never())->method('update');

		$this->expectException(ValidationException::class);
		$this->service->bulkSet(2026, 99, 28.0, null);
	}
}
