<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\Entitlement;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\EntitlementService;
use OCA\Absence\Service\ImportService;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ImportServiceTest extends TestCase {
	private EntitlementService&MockObject $entitlements;
	private IUserManager&MockObject $userManager;
	private IDBConnection&MockObject $db;
	private ImportService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->entitlements = $this->createMock(EntitlementService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('userExists')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, ['alice', 'bob'], true),
		);
		$mail = $this->createMock(IUser::class);
		$mail->method('getUID')->willReturn('bob');
		$this->userManager->method('getByEmail')->willReturnCallback(
			static fn (string $email): array => $email === 'bob@example.com' ? [$mail] : [],
		);
		$leaveTypeMapper = $this->createMock(LeaveTypeMapper::class);
		$annual = new LeaveType();
		$annual->setId(1);
		$annual->setKey('annual');
		$annual->setCountsAgainstBalance(true);
		$sick = new LeaveType();
		$sick->setId(2);
		$sick->setKey('sick');
		$sick->setCountsAgainstBalance(false);
		$leaveTypeMapper->method('findAll')->willReturn([$annual, $sick]);
		$employees = $this->createMock(EmployeeDirectory::class);
		$employees->method('isEmployee')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, ['alice', 'bob'], true),
		);
		$this->db = $this->createMock(IDBConnection::class);
		$this->service = new ImportService(
			$this->entitlements,
			$leaveTypeMapper,
			$employees,
			$this->userManager,
			$this->db,
		);
	}

	public function testPlansRowsResolvingEmailsAndDefaults(): void {
		$csv = "user,base_days,carry_over_days,adjustment,note\n"
			. "alice,28,3,,\n"
			. "bob@example.com,30,,2,Overtime compensation\n";

		$plan = $this->service->plan($csv, 2026);

		self::assertCount(2, $plan);
		self::assertSame('alice', $plan[0]['uid']);
		self::assertSame(2026, $plan[0]['year']);
		self::assertSame(['baseDays' => 28.0, 'carryOverDays' => 3.0, 'adjustmentNote' => 'Imported from CSV'], $plan[0]['data']);
		self::assertSame('bob', $plan[1]['uid']);
		self::assertSame(['baseDays' => 30.0, 'manualAdjustment' => 2.0, 'adjustmentNote' => 'Overtime compensation'], $plan[1]['data']);
	}

	public function testSemicolonSeparatedGermanExcelExportsWork(): void {
		$csv = "\xEF\xBB\xBFuser;base_days\nalice;28\n";

		$plan = $this->service->plan($csv, 2026);

		self::assertSame(28.0, $plan[0]['data']['baseDays']);
	}

	public function testAnyBrokenRowAbortsTheWholeImport(): void {
		// Half-imported companies are worse than no import: every error is
		// reported and nothing is written.
		$csv = "user,base_days\nalice,28\nghost,30\nbob,not-a-number\n";
		$this->entitlements->expects(self::never())->method('setForEmployee');

		try {
			$this->service->plan($csv, 2026);
			self::fail('expected a ValidationException');
		} catch (ValidationException $e) {
			self::assertStringContainsString("line 3: unknown user 'ghost'", $e->getMessage());
			self::assertStringContainsString("line 4: 'base_days' is not a number", $e->getMessage());
		}
	}

	public function testNonCountingTypesAreRefused(): void {
		$csv = "user,type,base_days\nalice,sick,10\n";

		$this->expectException(ValidationException::class);
		$this->service->plan($csv, 2026);
	}

	public function testApplyWritesThroughTheEntitlementService(): void {
		// Through the service, not the mapper: the audited history is the point.
		$csv = "user,base_days\nalice,28\n";
		$plan = $this->service->plan($csv, 2026);
		$this->entitlements->expects(self::once())->method('setForEmployee')
			->with('import:cli', 'alice', 2026, 1, ['baseDays' => 28.0, 'adjustmentNote' => 'Imported from CSV']);
		// The whole batch is one transaction, committed once every row succeeded.
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		self::assertSame(1, $this->service->apply($plan, 'import:cli'));
	}

	public function testApplyRollsBackWhenARowFailsPartWay(): void {
		// "Half the company imported" is the outcome the all-or-nothing promise
		// exists to prevent: a mid-batch failure must roll the whole thing back.
		$csv = "user,base_days\nalice,28\nbob,30\n";
		$plan = $this->service->plan($csv, 2026);
		$calls = 0;
		$this->entitlements->method('setForEmployee')->willReturnCallback(
			function () use (&$calls): Entitlement {
				if (++$calls === 2) {
					throw new \RuntimeException('DB went away');
				}
				return $this->createMock(Entitlement::class);
			},
		);
		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::never())->method('commit');
		$this->db->expects(self::once())->method('rollBack');

		$this->expectException(\RuntimeException::class);
		$this->service->apply($plan, 'import:cli');
	}
}
