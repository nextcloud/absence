<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\Entitlement;
use OCA\Absence\Db\EntitlementMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ConfigService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BalanceServiceTest extends TestCase {
	private LeaveRequestMapper&MockObject $requestMapper;
	private EntitlementMapper&MockObject $entitlementMapper;
	private LeaveTypeMapper&MockObject $leaveTypeMapper;
	private ConfigService&MockObject $config;
	private BalanceService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);
		$this->entitlementMapper = $this->createMock(EntitlementMapper::class);
		$this->leaveTypeMapper = $this->createMock(LeaveTypeMapper::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->service = new BalanceService(
			$this->requestMapper,
			$this->entitlementMapper,
			$this->leaveTypeMapper,
			$this->config,
		);
	}

	private function annualType(int $id = 1): LeaveType {
		$type = new LeaveType();
		$type->setId($id);
		$type->setKey('annual');
		$type->setLabel('Annual leave');
		$type->setCountsAgainstBalance(true);
		return $type;
	}

	private function request(int $id, string $status, float $days, ?int $supersedesId = null, string $start = '2026-08-03'): LeaveRequest {
		$request = new LeaveRequest();
		$request->setId($id);
		$request->setEmployeeUid('alice');
		$request->setTypeId(1);
		$request->setStartDate($start);
		$request->setEndDate($start);
		$request->setWorkingDays($days);
		$request->setStatus($status);
		$request->setSupersedesId($supersedesId);
		return $request;
	}

	/**
	 * The single annual-type balance row for alice in 2026 with a 30-day base.
	 *
	 * @param LeaveRequest[] $requests
	 * @return array<string,mixed>
	 */
	private function annualRow(array $requests): array {
		$this->requestMapper->method('findAllForEmployee')->with('alice')->willReturn($requests);
		$this->leaveTypeMapper->method('findAll')->willReturn([$this->annualType()]);
		$entitlement = new Entitlement();
		$entitlement->setId(7);
		$entitlement->setEmployeeUid('alice');
		$entitlement->setYear(2026);
		$entitlement->setTypeId(1);
		$entitlement->setBaseDays(30.0);
		$this->entitlementMapper->method('findFor')->with('alice', 2026, 1)->willReturn($entitlement);

		$rows = $this->service->getBalance('alice', 2026)['balances'];
		$this->assertCount(1, $rows);
		return $rows[0];
	}

	public function testPendingSupersedingEditOnlyCountsTheExtraDays(): void {
		$row = $this->annualRow([
			$this->request(10, LeaveRequest::STATUS_APPROVED, 5.0),
			$this->request(11, LeaveRequest::STATUS_PENDING, 8.0, 10, '2026-08-10'),
		]);
		$this->assertSame(5.0, $row['used']);
		$this->assertSame(3.0, $row['pending']);
		// 30 − 5 used − 3 genuinely pending; before the fix this was 30 − 5 − 8 = 17.
		$this->assertSame(22.0, $row['available']);
	}

	public function testPendingShrinkingEditAddsNothingPending(): void {
		$row = $this->annualRow([
			$this->request(10, LeaveRequest::STATUS_APPROVED, 5.0),
			$this->request(11, LeaveRequest::STATUS_PENDING, 3.0, 10, '2026-08-10'),
		]);
		$this->assertSame(5.0, $row['used']);
		$this->assertSame(0.0, $row['pending']);
		$this->assertSame(25.0, $row['available']);
	}

	public function testUnrelatedPendingRequestCountsInFull(): void {
		$row = $this->annualRow([
			$this->request(10, LeaveRequest::STATUS_APPROVED, 5.0),
			$this->request(11, LeaveRequest::STATUS_PENDING, 8.0, null, '2026-09-07'),
		]);
		$this->assertSame(5.0, $row['used']);
		$this->assertSame(8.0, $row['pending']);
		$this->assertSame(17.0, $row['available']);
	}

	public function testSupersedingEditOfClosedOriginalCountsInFull(): void {
		// The original is no longer counted anywhere, so there is nothing to net against.
		$row = $this->annualRow([
			$this->request(10, LeaveRequest::STATUS_CANCELLED, 5.0),
			$this->request(11, LeaveRequest::STATUS_PENDING, 8.0, 10, '2026-08-10'),
		]);
		$this->assertSame(0.0, $row['used']);
		$this->assertSame(8.0, $row['pending']);
		$this->assertSame(22.0, $row['available']);
	}

	public function testEnsureEntitlementForAnnualUsesConfiguredDefault(): void {
		$this->entitlementMapper->method('findFor')->willThrowException(new DoesNotExistException(''));
		$this->leaveTypeMapper->method('find')->with(1)->willReturn($this->annualType());
		$this->config->method('getDefaultEntitlement')->willReturn(25.0);
		$this->entitlementMapper->method('insert')->willReturnArgument(0);

		$entitlement = $this->service->ensureEntitlement('alice', 2027, 1);
		$this->assertSame(25.0, $entitlement->getBaseDays());
	}

	public function testEnsureEntitlementForOtherTypesStartsAtZero(): void {
		$type = new LeaveType();
		$type->setId(2);
		$type->setKey('special');
		$type->setCountsAgainstBalance(true);
		$this->entitlementMapper->method('findFor')->willThrowException(new DoesNotExistException(''));
		$this->leaveTypeMapper->method('find')->with(2)->willReturn($type);
		$this->config->method('getDefaultEntitlement')->willReturn(25.0);
		$this->entitlementMapper->method('insert')->willReturnArgument(0);

		$entitlement = $this->service->ensureEntitlement('alice', 2027, 2);
		$this->assertSame(0.0, $entitlement->getBaseDays());
	}
}
