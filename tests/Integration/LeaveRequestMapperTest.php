<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The mapper's hand-written SQL against a real database — the aggregation,
 * the conditional escalation flip and the covering-absence query are exactly
 * the pieces unit tests with mocked mappers cannot exercise.
 */
class LeaveRequestMapperTest extends TestCase {
	private const UID = 'itest-mapper-emp';
	private const MANAGER = 'itest-mapper-mgr';

	private LeaveRequestMapper $mapper;
	/** @var LeaveRequest[] */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = Server::get(LeaveRequestMapper::class);
	}

	protected function tearDown(): void {
		foreach ($this->created as $request) {
			try {
				$this->mapper->delete($request);
			} catch (\Throwable) {
				// already gone
			}
		}
		$this->created = [];
		parent::tearDown();
	}

	private function seed(string $uid, string $status, string $start, string $end, float $days, int $typeId = 1, ?int $supersedesId = null): LeaveRequest {
		$request = new LeaveRequest();
		$request->setEmployeeUid($uid);
		$request->setManagerUid(self::MANAGER);
		$request->setTypeId($typeId);
		$request->setStartDate($start);
		$request->setEndDate($end);
		$request->setWorkingDays($days);
		$request->setStatus($status);
		$request->setSupersedesId($supersedesId);
		$request->setCreatedAt(new \DateTime('2026-01-01 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2026-01-01 09:00:00'));
		return $this->created[] = $this->mapper->insert($request);
	}

	public function testAggregateWorkingDaysGroupsByTypeAndStatus(): void {
		$this->seed(self::UID, LeaveRequest::STATUS_APPROVED, '2026-02-02', '2026-02-06', 5.0);
		$this->seed(self::UID, LeaveRequest::STATUS_APPROVED, '2026-03-02', '2026-03-03', 2.0);
		$this->seed(self::UID, LeaveRequest::STATUS_PENDING, '2026-04-06', '2026-04-08', 3.0);
		$this->seed(self::UID, LeaveRequest::STATUS_REJECTED, '2026-05-04', '2026-05-08', 5.0); // terminal: not aggregated
		$this->seed(self::UID, LeaveRequest::STATUS_APPROVED, '2025-06-01', '2025-06-05', 5.0); // other year

		$rows = $this->mapper->aggregateWorkingDaysForYear([self::UID], 2026);

		$byStatus = [];
		foreach ($rows as $row) {
			self::assertSame(self::UID, $row['employee_uid']);
			$byStatus[$row['status']] = $row['days'];
		}
		self::assertEqualsWithDelta(7.0, $byStatus[LeaveRequest::STATUS_APPROVED], 0.001);
		self::assertEqualsWithDelta(3.0, $byStatus[LeaveRequest::STATUS_PENDING], 0.001);
		self::assertArrayNotHasKey(LeaveRequest::STATUS_REJECTED, $byStatus);
	}

	public function testMarkEscalatedFlipsExactlyOncePerPendingRequest(): void {
		$pending = $this->seed(self::UID, LeaveRequest::STATUS_PENDING, '2026-09-07', '2026-09-11', 5.0);
		$approved = $this->seed(self::UID, LeaveRequest::STATUS_APPROVED, '2026-10-05', '2026-10-09', 5.0);
		$now = new \DateTime('2026-01-02 10:00:00');

		// The pending one flips exactly once; repeating is a no-op.
		self::assertTrue($this->mapper->markEscalated((int)$pending->getId(), $now));
		self::assertFalse($this->mapper->markEscalated((int)$pending->getId(), $now));
		// A decided request is never clobbered back to ESCALATED.
		self::assertFalse($this->mapper->markEscalated((int)$approved->getId(), $now));

		self::assertSame(LeaveRequest::STATUS_ESCALATED, $this->mapper->find((int)$pending->getId())->getStatus());
		self::assertSame(LeaveRequest::STATUS_APPROVED, $this->mapper->find((int)$approved->getId())->getStatus());
	}

	public function testHasApprovedAbsenceCoveringMatchesWholeRangesOnly(): void {
		$this->seed(self::MANAGER, LeaveRequest::STATUS_APPROVED, '2026-08-10', '2026-08-21', 10.0);

		self::assertTrue($this->mapper->hasApprovedAbsenceCovering(self::MANAGER, '2026-08-12', '2026-08-20'));
		self::assertTrue($this->mapper->hasApprovedAbsenceCovering(self::MANAGER, '2026-08-10', '2026-08-21'));
		// Partially covered ranges do not count as "away the whole time".
		self::assertFalse($this->mapper->hasApprovedAbsenceCovering(self::MANAGER, '2026-08-12', '2026-08-24'));
		self::assertFalse($this->mapper->hasApprovedAbsenceCovering(self::MANAGER, '2026-09-01', '2026-09-01'));
	}

	public function testCountPendingForManagerCountsPendingAndWithdrawals(): void {
		$before = $this->mapper->countPendingForManager(self::MANAGER);
		$this->seed(self::UID, LeaveRequest::STATUS_PENDING, '2026-11-02', '2026-11-06', 5.0);
		$this->seed(self::UID, LeaveRequest::STATUS_WITHDRAWAL_PENDING, '2026-11-16', '2026-11-20', 5.0);
		$this->seed(self::UID, LeaveRequest::STATUS_APPROVED, '2026-11-23', '2026-11-27', 5.0);

		self::assertSame($before + 2, $this->mapper->countPendingForManager(self::MANAGER));
	}
}
