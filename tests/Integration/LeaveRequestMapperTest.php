<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Db\Attachment;
use OCA\Absence\Db\AttachmentMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCP\IDBConnection;
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

	public function testFindFilteredKeepsOnlyTheStatusesAskedFor(): void {
		$pending = $this->seed(self::UID, LeaveRequest::STATUS_PENDING, '2026-04-06', '2026-04-08', 3.0);
		$withdrawal = $this->seed(self::UID, LeaveRequest::STATUS_WITHDRAWAL_PENDING, '2026-03-02', '2026-03-03', 2.0);
		$this->seed(self::UID, LeaveRequest::STATUS_APPROVED, '2026-05-04', '2026-05-08', 5.0);
		$this->seed(self::UID, LeaveRequest::STATUS_REJECTED, '2026-06-01', '2026-06-05', 5.0);

		$rows = $this->mapper->findFiltered([
			'managerUid' => self::MANAGER,
			'status' => [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_WITHDRAWAL_PENDING],
		]);

		self::assertSame([$pending->getId(), $withdrawal->getId()], array_map(static fn (LeaveRequest $r): int => $r->getId(), $rows));
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

	public function testFindForUpdateReadsWhatWasWrittenAfterTheEntityWasLoaded(): void {
		$loaded = $this->seed(self::UID, LeaveRequest::STATUS_PENDING, '2026-03-02', '2026-03-03', 2.0);
		// Somebody else decides the request after it was loaded.
		$other = $this->mapper->find($loaded->getId());
		$other->setStatus(LeaveRequest::STATUS_CANCELLED);
		$this->mapper->update($other);

		$db = Server::get(IDBConnection::class);
		$db->beginTransaction();
		try {
			$fresh = $this->mapper->findForUpdate($loaded->getId());
		} finally {
			$db->rollBack();
		}

		self::assertSame(LeaveRequest::STATUS_PENDING, $loaded->getStatus());
		self::assertSame(LeaveRequest::STATUS_CANCELLED, $fresh->getStatus());
	}

	public function testHasApprovedAbsenceCoveringMatchesWholeRangesOnly(): void {
		$this->seed(self::MANAGER, LeaveRequest::STATUS_APPROVED, '2026-08-10', '2026-08-21', 10.0);

		self::assertTrue($this->mapper->hasApprovedAbsenceCovering(self::MANAGER, '2026-08-12', '2026-08-20'));
		self::assertTrue($this->mapper->hasApprovedAbsenceCovering(self::MANAGER, '2026-08-10', '2026-08-21'));
		// Partially covered ranges do not count as "away the whole time".
		self::assertFalse($this->mapper->hasApprovedAbsenceCovering(self::MANAGER, '2026-08-12', '2026-08-24'));
		self::assertFalse($this->mapper->hasApprovedAbsenceCovering(self::MANAGER, '2026-09-01', '2026-09-01'));
	}

	public function testCountPendingForEmployeesCountsPendingAndWithdrawals(): void {
		$this->seed(self::UID, LeaveRequest::STATUS_PENDING, '2026-11-02', '2026-11-06', 5.0);
		$this->seed(self::UID, LeaveRequest::STATUS_WITHDRAWAL_PENDING, '2026-11-16', '2026-11-20', 5.0);
		$this->seed(self::UID, LeaveRequest::STATUS_APPROVED, '2026-11-23', '2026-11-27', 5.0);

		self::assertSame(2, $this->mapper->countPendingForEmployees([self::UID]));
		self::assertSame(0, $this->mapper->countPendingForEmployees([]));
	}

	public function testALongListOfEmployeesIsSearchedWhole(): void {
		$pending = $this->seed(self::UID, LeaveRequest::STATUS_PENDING, '2026-11-02', '2026-11-06', 5.0);
		// More than one IN() list holds, with the one who has a request last
		$uids = [...array_map(static fn (int $i): string => 'itest-mapper-nobody-' . $i, range(1, 2500)), self::UID];

		self::assertSame(1, $this->mapper->countPendingForEmployees($uids));
		self::assertSame([$pending->getId()], array_map(static fn (LeaveRequest $r): int => $r->getId(), $this->mapper->findPendingForEmployees($uids)));
		self::assertSame([$pending->getId()], array_map(static fn (LeaveRequest $r): int => $r->getId(), $this->mapper->findFiltered(['employeeUids' => $uids])));
	}

	/**
	 * More values than any supported database takes in one statement (SQLite
	 * is the most generous with 250000), with the wanted ones at both ends of
	 * the list.
	 *
	 * @template T of int|string
	 * @param T $first
	 * @param T $last
	 * @param \Closure(int):T $filler
	 * @return list<T>
	 */
	private static function longList(int|string $first, int|string $last, \Closure $filler): array {
		return [$first, ...array_map($filler, range(1, 250000)), $last];
	}

	public function testTheYearQueriesTakeMoreEmployeesThanOneStatementHolds(): void {
		$approved = $this->seed(self::UID, LeaveRequest::STATUS_APPROVED, '2026-02-02', '2026-02-06', 5.0);
		$edit = $this->seed(self::MANAGER, LeaveRequest::STATUS_PENDING, '2026-02-03', '2026-02-06', 4.0, 1, (int)$approved->getId());
		$uids = self::longList(self::UID, self::MANAGER, static fn (int $i): string => 'itest-mapper-nobody-' . $i);

		$days = [];
		foreach ($this->mapper->aggregateWorkingDaysForYear($uids, 2026) as $row) {
			$days[$row['employee_uid']] = $row['days'];
		}
		self::assertEqualsWithDelta(5.0, $days[self::UID], 0.001);
		self::assertEqualsWithDelta(4.0, $days[self::MANAGER], 0.001);
		self::assertCount(2, $days);

		$pendingEdits = $this->mapper->findPendingSupersedingForYear($uids, 2026);
		self::assertSame([$edit->getId()], array_map(static fn (LeaveRequest $r): int => $r->getId(), $pendingEdits));
	}

	public function testFindByIdsTakesMoreIdsThanOneStatementHolds(): void {
		$first = (int)$this->seed(self::UID, LeaveRequest::STATUS_APPROVED, '2026-02-02', '2026-02-06', 5.0)->getId();
		$last = (int)$this->seed(self::UID, LeaveRequest::STATUS_APPROVED, '2026-03-02', '2026-03-06', 5.0)->getId();

		// Negative ids never exist
		$found = $this->mapper->findByIds(self::longList($first, $last, static fn (int $i): int => -$i));

		self::assertSame([$first, $last], array_keys($found));
	}

	public function testAttachmentsOfMoreRequestsThanOneStatementHolds(): void {
		$attachments = Server::get(AttachmentMapper::class);
		$stored = [];
		foreach ([-1, -2] as $requestId) {
			$attachment = new Attachment();
			$attachment->setRequestId($requestId);
			$attachment->setUploaderUid(self::UID);
			$attachment->setName('itest-note.pdf');
			$attachment->setCreatedAt(new \DateTime('2026-01-01 09:00:00'));
			$stored[] = $attachments->insert($attachment);
		}

		try {
			$found = $attachments->findForRequests(self::longList(-1, -2, static fn (int $i): int => -2 - $i));
			self::assertSame(
				array_map(static fn (Attachment $a): int => $a->getId(), $stored),
				array_map(static fn (Attachment $a): int => $a->getId(), $found),
			);
		} finally {
			foreach ($stored as $attachment) {
				$attachments->delete($attachment);
			}
		}
	}
}
