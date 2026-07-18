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
use OCA\Absence\Service\ExportService;
use OCA\Absence\Service\ReportService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExportServiceTest extends TestCase {
	private LeaveRequestMapper&MockObject $requestMapper;
	private LeaveTypeMapper&MockObject $leaveTypeMapper;
	private ReportService&MockObject $reportService;
	private IUserManager&MockObject $userManager;
	private ExportService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);
		$this->leaveTypeMapper = $this->createMock(LeaveTypeMapper::class);
		$this->reportService = $this->createMock(ReportService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->service = new ExportService(
			$this->requestMapper,
			$this->leaveTypeMapper,
			$this->reportService,
			$this->userManager,
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
}
