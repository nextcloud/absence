<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\CarryOverReminderService;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\NotificationService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Carry-over expires on 2032-03-31, so the reminder is due from 2032-03-01
 * (30 days ahead). Robin would lose 3 days, Kim nothing.
 */
class CarryOverReminderServiceTest extends TestCase {
	private BalanceService&MockObject $balances;
	private ?string $expiryDate = '2032-03-31';
	private string $today = '2032-03-01';
	/** @var array<string,int> the stored markers */
	private array $stored = [ConfigLexicon::KEY_LAST_ROLLOVER_YEAR => 2032];
	/** @var array<string,list<array<string,mixed>>> */
	private array $rows = [];
	/** @var list<array{string,float,string}> */
	private array $sent = [];
	private bool $failFirst = false;
	private CarryOverReminderService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->rows = [
			'robin' => [$this->row(1, 3.0)],
			'kim' => [$this->row(1, 0.0)],
		];
		$this->balances = $this->createMock(BalanceService::class);
		$this->balances->method('carryOverExpiryDate')->willReturnCallback(fn (): ?string => $this->expiryDate);
		$this->balances->method('getBalancesForEmployees')->willReturnCallback(fn (): array => $this->rows);

		$employees = $this->createMock(EmployeeDirectory::class);
		$employees->method('listAll')->willReturnCallback(fn (): array => array_keys($this->rows));

		$types = $this->createMock(LeaveTypeMapper::class);
		$types->method('hrOnlyTypeIds')->willReturn([9]);

		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('notifyCarryOverExpiring')->willReturnCallback(function (string $uid, float $days, string $date): void {
			if ($this->failFirst) {
				$this->failFirst = false;
				throw new \RuntimeException('mail server down');
			}
			$this->sent[] = [$uid, $days, $date];
		});

		$clock = $this->createMock(ClockService::class);
		$clock->method('serverToday')->willReturnCallback(fn (): string => $this->today);
		$clock->method('serverYear')->willReturnCallback(fn (): int => (int)substr($this->today, 0, 4));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key): int => $this->stored[$key] ?? 0);
		$appConfig->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->stored[$key] = $value;
			return true;
		});

		$this->service = new CarryOverReminderService(
			$this->balances,
			$employees,
			$types,
			$notifications,
			$clock,
			$appConfig,
			$this->createMock(LoggerInterface::class),
		);
	}

	/** @return array<string,mixed> */
	private function row(int $typeId, float $expiring): array {
		return ['typeId' => $typeId, 'carryOverExpiring' => $expiring, 'carryOverExpiryDate' => $expiring > 0 ? '2032-03-31' : null];
	}

	private function runOn(string $day): int {
		$this->today = $day;
		return $this->service->remind();
	}

	public function testRemindsOnlyThoseWithDaysToLose(): void {
		// every balance in one call, not one per employee
		$this->balances->expects(self::once())->method('getBalancesForEmployees')->with(['robin', 'kim'], 2032);

		self::assertSame(1, $this->runOn('2032-03-01'));
		self::assertSame([['robin', 3.0, '2032-03-31']], $this->sent);
	}

	public function testLaterRunsRemindNobodyAgain(): void {
		$this->runOn('2032-03-01');
		$this->runOn('2032-03-01');
		$this->runOn('2032-03-02');
		$this->runOn('2032-03-31');

		self::assertCount(1, $this->sent);
	}

	public function testNothingIsSentBeforeTheLeadDay(): void {
		self::assertSame(0, $this->runOn('2032-02-29'));
		self::assertSame([], $this->sent);
		// and the year is not marked as done
		self::assertSame(1, $this->runOn('2032-03-01'));
	}

	public function testMissedLeadDayIsCaughtUpUntilTheExpiryDate(): void {
		self::assertSame(1, $this->runOn('2032-03-31'));
	}

	public function testNothingIsSentAfterTheExpiryDate(): void {
		self::assertSame(0, $this->runOn('2032-04-01'));
		self::assertSame([], $this->sent);
	}

	public function testNothingIsSentWithoutAnExpiryDate(): void {
		$this->expiryDate = null;
		self::assertSame(0, $this->runOn('2032-03-10'));
	}

	public function testNextYearIsRemindedAgain(): void {
		$this->runOn('2032-03-01');
		$this->expiryDate = '2033-03-31';
		$this->stored[ConfigLexicon::KEY_LAST_ROLLOVER_YEAR] = 2033;

		self::assertSame(1, $this->runOn('2033-03-05'));
		self::assertCount(2, $this->sent);
	}

	public function testWaitsForTheYearRollover(): void {
		// expiry on 15 January: due from 1 January, when nothing is carried over yet
		$this->expiryDate = '2032-01-15';
		$this->stored[ConfigLexicon::KEY_LAST_ROLLOVER_YEAR] = 2031;
		self::assertSame(0, $this->runOn('2032-01-01'));

		$this->stored[ConfigLexicon::KEY_LAST_ROLLOVER_YEAR] = 2032;
		self::assertSame(1, $this->runOn('2032-01-02'));
	}

	public function testDaysOfSeveralTypesAreAddedUpWithoutTheConfidentialOnes(): void {
		$this->rows = [
			'robin' => [$this->row(1, 3.0), $this->row(2, 1.5), $this->row(9, 4.0)],
			'kim' => [$this->row(9, 2.0)],
		];

		$this->runOn('2032-03-01');

		self::assertSame([['robin', 4.5, '2032-03-31']], $this->sent);
	}

	public function testOneFailedReminderDoesNotCostTheOthersTheirs(): void {
		$this->rows = ['robin' => [$this->row(1, 3.0)], 'sam' => [$this->row(1, 2.0)]];
		$this->failFirst = true;

		self::assertSame(1, $this->runOn('2032-03-01'));
		self::assertSame([['sam', 2.0, '2032-03-31']], $this->sent);
	}
}
