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
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\ActivityPublisher;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\EntitlementService;
use OCA\Absence\Service\EntitlementValidator;
use OCA\Absence\Tests\Unit\ClockMockTrait;
use OCA\Absence\Tests\Unit\L10nMockTrait;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EntitlementServiceTest extends TestCase {
	use ClockMockTrait;
	use L10nMockTrait;

	private EntitlementMapper&MockObject $entitlementMapper;
	private EntitlementEventMapper&MockObject $eventMapper;
	private LeaveTypeMapper&MockObject $leaveTypeMapper;
	private BalanceService&MockObject $balanceService;
	private ConfigService&MockObject $config;
	private LoggerInterface&MockObject $logger;
	private IEventDispatcher&MockObject $dispatcher;
	private EntitlementService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->entitlementMapper = $this->createMock(EntitlementMapper::class);
		$this->leaveTypeMapper = $this->createMock(LeaveTypeMapper::class);
		$this->balanceService = $this->createMock(BalanceService::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->eventMapper = $this->createMock(EntitlementEventMapper::class);
		$this->eventMapper->method('insert')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->service = new EntitlementService(
			$this->entitlementMapper,
			$this->eventMapper,
			$this->createMock(LeaveRequestMapper::class),
			$this->leaveTypeMapper,
			$this->balanceService,
			$this->config,
			$this->clockAtRealTime(),
			$this->createMock(ActivityPublisher::class),
			new EmployeeDirectory(
				$this->createMock(IUserManager::class),
				$this->createMock(IGroupManager::class),
				$this->createMock(ConfigService::class),
				$this->createMock(LoggerInterface::class),
			),
			new EntitlementValidator($this->l10nMock()),
			$this->createMock(IDBConnection::class),
			$this->l10nMock(),
			$this->logger,
			$this->dispatcher,
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

	/**
	 * Run the 2026 rollover for bob and return the 2027 row it wrote.
	 */
	private function rollOverBob(Entitlement $prior, float $remaining, ?Entitlement $existing = null): Entitlement {
		$this->entitlementMapper->method('findForYear')->willReturnMap([
			[2026, [$prior]],
			[2027, $existing === null ? [] : [$existing]],
		]);
		$this->balanceService->method('getBalancesForEmployees')->with(['bob'], 2026)
			->willReturn(['bob' => [['typeId' => 1, 'remaining' => $remaining]]]);
		$written = null;
		$capture = static function (Entitlement $e) use (&$written) {
			$written = $e;
			return $e;
		};
		$this->entitlementMapper->expects($existing === null ? $this->once() : $this->never())->method('insert')->willReturnCallback($capture);
		$this->entitlementMapper->expects($existing === null ? $this->never() : $this->once())->method('update')->willReturnCallback($capture);

		$this->assertSame(['affected' => 1, 'skipped' => 0, 'failed' => 0], $this->service->rollover(2026));

		$this->assertNotNull($written);
		return $written;
	}

	public function testRolloverCarriesPriorBaseNotGlobalDefault(): void {
		$this->config->method('getCarryOverPolicy')->willReturn(ConfigService::CARRYOVER_UNLIMITED);
		$this->config->expects($this->never())->method('getDefaultEntitlement');
		$this->balanceService->expects($this->never())->method('ensureEntitlement');

		$written = $this->rollOverBob($this->priorEntitlement(5.0), 2.0);

		// HR granted 5 days in 2026; 2027 must continue that base, not the 25-day default.
		$this->assertSame(5.0, $written->getBaseDays());
		$this->assertSame(2.0, $written->getCarryOverDays());
		$this->assertSame(2027, $written->getYear());
	}

	public function testRolloverKeepsExistingNextYearBase(): void {
		$this->config->method('getCarryOverPolicy')->willReturn(ConfigService::CARRYOVER_UNLIMITED);
		$existing = new Entitlement();
		$existing->setId(2);
		$existing->setEmployeeUid('bob');
		$existing->setYear(2027);
		$existing->setTypeId(1);
		$existing->setBaseDays(12.0);

		$written = $this->rollOverBob($this->priorEntitlement(5.0), 2.0, $existing);

		// A base HR already set for the new year is never touched; only carry-over is.
		$this->assertSame(12.0, $written->getBaseDays());
		$this->assertSame(2.0, $written->getCarryOverDays());
	}

	public function testRolloverWithCappedPolicyCapsCarryOver(): void {
		$this->config->method('getCarryOverPolicy')->willReturn(ConfigService::CARRYOVER_CAPPED);
		$this->config->method('getCarryOverCap')->willReturn(10.0);

		$written = $this->rollOverBob($this->priorEntitlement(30.0), 14.0);

		$this->assertSame(30.0, $written->getBaseDays());
		$this->assertSame(10.0, $written->getCarryOverDays());
	}

	public function testRolloverReportsTheRowsThatFailed(): void {
		$this->config->method('getCarryOverPolicy')->willReturn(ConfigService::CARRYOVER_UNLIMITED);
		$this->entitlementMapper->method('findForYear')->willReturnMap([[2026, [$this->priorEntitlement(5.0)]], [2027, []]]);
		$this->balanceService->method('getBalancesForEmployees')->willReturn(['bob' => [['typeId' => 1, 'remaining' => 2.0]]]);
		$this->entitlementMapper->method('insert')->willThrowException(new \RuntimeException('database is away'));

		$this->assertSame(['affected' => 0, 'skipped' => 0, 'failed' => 1], $this->service->rollover(2026));
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

	public function testAnEntitlementChangeGoesToTheLogsWithIdsOnly(): void {
		$ent = $this->priorEntitlement(28.0);
		$ent->setId(1);
		$ent->setManualAdjustment(0.0);
		$this->entitlementMapper->method('find')->with(1)->willReturn($ent);
		$this->entitlementMapper->method('update')->willReturnArgument(0);
		$logged = [];
		$this->logger->method('info')->willReturnCallback(function (string $message, array $context) use (&$logged): void {
			$logged[] = $context;
		});
		$audited = [];
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(function (CriticalActionPerformedEvent $event) use (&$audited): void {
			$audited[] = vsprintf($event->getLogMessage(), $event->getParameters());
		});

		$this->service->update('hr', 1, ['manualAdjustment' => 2.0, 'adjustmentNote' => 'Wedding']);

		self::assertSame([[
			'app' => 'absence',
			'action' => 'entitlement_updated',
			'actor' => 'hr',
			'entitlementId' => 1,
			'employee' => 'bob',
			'year' => $ent->getYear(),
		]], $logged);
		self::assertSame(['Absence entitlement 1 updated by hr'], $audited);
	}

	/**
	 * The reported bug, in full. 25 days, "+2 wedding", then "−2 booked in error"
	 * has to land back on 25 — it landed on 23, because the second correction
	 * *replaced* the first instead of adding to it.
	 */
	public function testTwoOppositeCorrectionsCancelOut(): void {
		$ent = $this->priorEntitlement(25.0);
		$ent->setManualAdjustment(0.0);
		$this->entitlementMapper->method('find')->with(1)->willReturn($ent);
		$this->entitlementMapper->method('update')->willReturnArgument(0);

		$after = $this->service->update('hr', 1, ['adjustmentDelta' => 2.0, 'adjustmentNote' => 'Wedding']);
		self::assertSame(2.0, $after->getManualAdjustment());
		self::assertSame(27.0, $after->getEntitlement());

		$after = $this->service->update('hr', 1, ['adjustmentDelta' => -2.0, 'adjustmentNote' => 'Booked in error']);
		self::assertSame(0.0, $after->getManualAdjustment(), 'the two corrections must cancel');
		self::assertSame(25.0, $after->getEntitlement(), 'the allowance returns to where it started');
	}

	public function testAbsoluteAdjustmentStillSetsTheTotalOutright(): void {
		$ent = $this->priorEntitlement(25.0);
		$ent->setManualAdjustment(2.0);
		$this->entitlementMapper->method('find')->with(1)->willReturn($ent);
		$this->entitlementMapper->method('update')->willReturnArgument(0);

		// The other half of the contract: manualAdjustment overwrites rather than adds.
		$after = $this->service->update('hr', 1, ['manualAdjustment' => 5.0, 'adjustmentNote' => 'Recount']);
		self::assertSame(5.0, $after->getManualAdjustment());
	}

	public function testSendingBothAdjustmentFormsIsRefused(): void {
		$ent = $this->priorEntitlement(25.0);
		$this->entitlementMapper->method('find')->with(1)->willReturn($ent);

		$this->expectException(ValidationException::class);
		$this->service->update('hr', 1, [
			'adjustmentDelta' => 2.0,
			'manualAdjustment' => 5.0,
			'adjustmentNote' => 'Ambiguous',
		]);
	}

	public function testSavingWithoutChangingAnythingRecordsNothing(): void {
		$ent = $this->priorEntitlement(28.0);
		$this->entitlementMapper->method('find')->with(1)->willReturn($ent);
		$this->entitlementMapper->method('update')->willReturnArgument(0);

		// Re-saving the same figures is not a change and must not litter the history.
		$this->eventMapper->expects(self::never())->method('insert');

		$this->service->update('hr', 1, ['baseDays' => 28.0]);
	}

	public function testAFigureWithTwoDecimalsIsRefusedNotRounded(): void {
		$this->entitlementMapper->method('find')->with(1)->willReturn($this->priorEntitlement(28.0));
		$this->entitlementMapper->expects(self::never())->method('update');

		$this->expectException(ValidationException::class);
		$this->service->update('hr', 1, ['baseDays' => 28.25]);
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
		$this->service->bulkSet('hr', 2026, 99, 28.0, null);
	}
}
