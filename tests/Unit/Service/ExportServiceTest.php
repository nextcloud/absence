<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\ExportService;
use OCA\Absence\Service\ReportService;
use OCA\Absence\Tests\Unit\L10nMockTrait;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExportServiceTest extends TestCase {
	use L10nMockTrait;
	private LeaveRequestMapper&MockObject $requestMapper;
	private LeaveTypeMapper&MockObject $leaveTypeMapper;
	private ReportService&MockObject $reportService;
	private IUserManager&MockObject $userManager;
	private EmployeeDirectory&MockObject $employees;
	private ExportService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);
		$this->leaveTypeMapper = $this->createMock(LeaveTypeMapper::class);
		$this->reportService = $this->createMock(ReportService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->employees = $this->createMock(EmployeeDirectory::class);
		$this->service = new ExportService(
			$this->requestMapper,
			$this->leaveTypeMapper,
			$this->reportService,
			$this->employees,
			$this->userManager,
			$this->l10nMock(),
		);
	}

	public function testRequestsCsvNeutralizesFormulaInjection(): void {
		$this->leaveTypeMapper->method('findAll')->willReturn([]);

		$request = new LeaveRequest();
		$request->setId(1);
		$request->setEmployeeUid('evil');
		$request->setTypeId(7);
		$request->setStartDate('2026-01-05');
		$request->setEndDate('2026-01-06');
		$request->setWorkingDays(2.0);
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		$this->requestMapper->method('findAllInRange')->willReturn([$request]);

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('=HYPERLINK("https://evil/")');
		$this->userManager->method('get')->willReturn($user);

		$csv = $this->service->requestsCsv('2026-01-01', '2026-01-31')['content'];

		// The dangerous cell must be prefixed with an apostrophe so spreadsheets treat it as text.
		self::assertStringContainsString('\'=HYPERLINK', $csv);
		self::assertStringNotContainsString(',=HYPERLINK', $csv);
	}

	public function testRequestsCsvRejectsInvalidDate(): void {
		$this->expectException(ValidationException::class);
		$this->service->requestsCsv('not-a-date', '2026-01-31');
	}

	public function testRequestsCsvRejectsExcessiveRange(): void {
		$this->expectException(ValidationException::class);
		$this->service->requestsCsv('1970-01-01', '2200-01-01');
	}

	public function testRequestsCsvFiltersByGroup(): void {
		$this->leaveTypeMapper->method('findAll')->willReturn([]);
		$alice = new LeaveRequest();
		$alice->setId(1);
		$alice->setEmployeeUid('alice');
		$alice->setTypeId(1);
		$alice->setStartDate('2026-01-05');
		$alice->setEndDate('2026-01-06');
		$alice->setWorkingDays(2.0);
		$alice->setStatus(LeaveRequest::STATUS_APPROVED);
		$bob = clone $alice;
		$bob->setId(2);
		$bob->setEmployeeUid('bob');
		$this->requestMapper->method('findAllInRange')->willReturn([$alice, $bob]);
		// An unknown group resolves to [] downstream, so the same path also keeps a
		// stale group name from widening the export to everyone.
		$this->employees->method('listInGroup')->with('team')->willReturn(['alice']);

		$export = $this->service->requestsCsv('2026-01-01', '2026-01-31', 'team');

		self::assertStringContainsString('alice', $export['content']);
		self::assertStringNotContainsString('bob', $export['content']);
		self::assertStringContainsString('team', $export['filename']);
	}

	public function testBalancesCsvPassesTheGroupToTheReport(): void {
		$this->reportService->expects(self::once())->method('balancesReport')
			->with(2026, 'team')->willReturn([]);

		$this->service->balancesCsv(2026, 'team');
	}

	private function user(string $displayName, ?string $email): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn($displayName);
		$user->method('getEMailAddress')->willReturn($email);
		return $user;
	}

	private function approvedRequest(int $id, string $employeeUid): LeaveRequest {
		$request = new LeaveRequest();
		$request->setId($id);
		$request->setEmployeeUid($employeeUid);
		$request->setTypeId(1);
		$request->setStartDate('2026-01-05');
		$request->setEndDate('2026-01-06');
		$request->setWorkingDays(2.0);
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		return $request;
	}

	/** @return list<list<string>> the cells of every line */
	private function cells(string $csv): array {
		$lines = explode("\n", trim(substr($csv, 3)));
		return array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), $lines);
	}

	/** @return array<string,mixed> */
	private function balanceRow(string $uid, string $displayName, string $typeLabel): array {
		return [
			'employeeUid' => $uid, 'displayName' => $displayName, 'year' => 2026, 'typeLabel' => $typeLabel,
			'entitlement' => 30.0, 'baseDays' => 30.0, 'carryOverDays' => 0.0, 'manualAdjustment' => 0.0,
			'used' => 2.0, 'pending' => 0.0, 'remaining' => 28.0, 'available' => 28.0,
		];
	}

	public function testRequestsCsvTellsTwoPeopleWithTheSameNameApart(): void {
		$this->leaveTypeMapper->method('findAll')->willReturn([]);
		$this->requestMapper->method('findAllInRange')->willReturn([
			$this->approvedRequest(1, 'jmiller'),
			$this->approvedRequest(2, 'jmiller2'),
		]);
		$users = [
			'jmiller' => $this->user('Jo Miller', 'jo.miller@example.com'),
			'jmiller2' => $this->user('Jo Miller', 'jo.miller2@example.com'),
		];
		// One lookup per row, as before the columns were added.
		$this->userManager->expects(self::exactly(2))->method('get')
			->willReturnCallback(static fn (string $uid): ?IUser => $users[$uid] ?? null);

		$rows = $this->cells($this->service->requestsCsv('2026-01-01', '2026-01-31')['content']);

		self::assertSame(
			['ID', 'Employee', 'Account ID', 'Email', 'Manager', 'Type', 'Start', 'End', 'Working days', 'Status', 'Decided by', 'Decided at'],
			$rows[0],
		);
		self::assertSame(['1', 'Jo Miller', 'jmiller', 'jo.miller@example.com', '', '1', '2026-01-05', '2026-01-06', '2', 'APPROVED', '', ''], $rows[1]);
		self::assertSame(['Jo Miller', 'jmiller2', 'jo.miller2@example.com'], array_slice($rows[2], 1, 3));
	}

	public function testRequestsCsvKeepsTheAccountIdOfADeletedAccount(): void {
		$this->leaveTypeMapper->method('findAll')->willReturn([]);
		$this->requestMapper->method('findAllInRange')->willReturn([$this->approvedRequest(1, 'gone')]);
		$this->userManager->method('get')->willReturn(null);

		$rows = $this->cells($this->service->requestsCsv('2026-01-01', '2026-01-31')['content']);

		self::assertSame(['gone', 'gone', ''], array_slice($rows[1], 1, 3));
	}

	public function testRequestsCsvNeutralizesAFormulaInTheAccountIdAndTheEmail(): void {
		$this->leaveTypeMapper->method('findAll')->willReturn([]);
		$this->requestMapper->method('findAllInRange')->willReturn([$this->approvedRequest(1, '=cmd')]);
		$this->userManager->method('get')->willReturn($this->user('Sam Okoro', '+sum(a1)@example.com'));

		$rows = $this->cells($this->service->requestsCsv('2026-01-01', '2026-01-31')['content']);

		self::assertSame(['Sam Okoro', "'=cmd", "'+sum(a1)@example.com"], array_slice($rows[1], 1, 3));
	}

	public function testBalancesCsvIdentifiesEachPersonAndLooksThemUpOnce(): void {
		$this->reportService->method('balancesReport')->willReturn([
			$this->balanceRow('jmiller', 'Jo Miller', 'Vacation'),
			$this->balanceRow('jmiller', 'Jo Miller', 'Special leave'),
			$this->balanceRow('jmiller2', 'Jo Miller', 'Vacation'),
			$this->balanceRow('gone', 'gone', 'Vacation'),
			$this->balanceRow('@evil', 'Sam Okoro', 'Vacation'),
		]);
		$users = [
			'jmiller' => $this->user('Jo Miller', 'jo.miller@example.com'),
			'jmiller2' => $this->user('Jo Miller', null),
			'@evil' => $this->user('Sam Okoro', '=1+1@example.com'),
		];
		// Five rows, four people: nobody is looked up twice.
		$this->userManager->expects(self::exactly(4))->method('get')
			->willReturnCallback(static fn (string $uid): ?IUser => $users[$uid] ?? null);

		$rows = $this->cells($this->service->balancesCsv(2026)['content']);

		self::assertSame(
			['Employee', 'Account ID', 'Email', 'Year', 'Type', 'Entitlement', 'Base', 'Carry-over', 'Adjustment', 'Used', 'Pending', 'Remaining', 'Available'],
			$rows[0],
		);
		self::assertSame(['Jo Miller', 'jmiller', 'jo.miller@example.com', '2026', 'Vacation', '30', '30', '0', '0', '2', '0', '28', '28'], $rows[1]);
		self::assertSame(['Jo Miller', 'jmiller', 'jo.miller@example.com', '2026', 'Special leave'], array_slice($rows[2], 0, 5));
		self::assertSame(['Jo Miller', 'jmiller2', ''], array_slice($rows[3], 0, 3));
		self::assertSame(['gone', 'gone', ''], array_slice($rows[4], 0, 3));
		self::assertSame(['Sam Okoro', "'@evil", "'=1+1@example.com"], array_slice($rows[5], 0, 3));
	}
}
