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
}
