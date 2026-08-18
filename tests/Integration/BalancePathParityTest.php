<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Service\BalanceService;
use OCP\Diagnostics\IQueryLogger;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The two balance code paths — the row-based single-employee computation and
 * the SQL-aggregated batch — must produce identical numbers on a real
 * database, netting rule included. The batch path is also held to a fixed
 * query budget (tests/Integration/base-query-count.txt), so the "fixed number
 * of queries regardless of headcount" claim stays true instead of regressing
 * one innocent-looking lookup at a time.
 */
class BalancePathParityTest extends TestCase {
	private const UIDS = ['itest-parity-a', 'itest-parity-b', 'itest-parity-c'];

	private LeaveRequestMapper $mapper;
	private BalanceService $balances;
	/** @var LeaveRequest[] */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = Server::get(LeaveRequestMapper::class);
		$this->balances = Server::get(BalanceService::class);
	}

	protected function tearDown(): void {
		foreach ($this->created as $request) {
			try {
				$this->mapper->delete($request);
			} catch (\Throwable) {
			}
		}
		$this->created = [];
		parent::tearDown();
	}

	private function seed(string $uid, string $status, string $start, string $end, float $days, ?int $supersedesId = null): LeaveRequest {
		$request = new LeaveRequest();
		$request->setEmployeeUid($uid);
		$request->setTypeId(1);
		$request->setStartDate($start);
		$request->setEndDate($end);
		$request->setWorkingDays($days);
		$request->setStatus($status);
		$request->setSupersedesId($supersedesId);
		$request->setCreatedAt(new \DateTime('2026-01-01 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2026-01-01 09:00:00'));
		return $this->created[] = $this->mapper->insert($request);
	}

	public function testSingleAndBatchPathsAgreeIncludingNetting(): void {
		[$a, $b, $c] = self::UIDS;
		// a: plain usage
		$this->seed($a, LeaveRequest::STATUS_APPROVED, '2026-02-02', '2026-02-06', 5.0);
		$this->seed($a, LeaveRequest::STATUS_PENDING, '2026-09-14', '2026-09-18', 5.0);
		// b: a pending superseding edit over a still-approved original (§5.3)
		$original = $this->seed($b, LeaveRequest::STATUS_APPROVED, '2026-03-02', '2026-03-06', 5.0);
		$this->seed($b, LeaveRequest::STATUS_PENDING, '2026-03-02', '2026-03-09', 7.0, (int)$original->getId());
		// c: nothing at all — still gets a default-entitlement row
		$batch = $this->balances->getBalancesForEmployees([$a, $b, $c], 2026);

		foreach (self::UIDS as $uid) {
			$single = $this->balances->getBalance($uid, 2026)['balances'];
			self::assertSameBalances($single, $batch[$uid], $uid);
		}

		// The netting itself: b's pending is the 2 extra days, not 7.
		$row = $this->annualRow($batch[$b]);
		self::assertEqualsWithDelta(5.0, $row['used'], 0.001);
		self::assertEqualsWithDelta(2.0, $row['pending'], 0.001);
	}

	public function testBatchPathStaysWithinItsQueryBudget(): void {
		$budget = (int)trim((string)file_get_contents(__DIR__ . '/base-query-count.txt'));
		$logger = Server::get(IQueryLogger::class);
		$logger->activate();
		$before = count($logger->getQueries());

		$this->balances->getBalancesForEmployees(self::UIDS, 2026);

		$used = count($logger->getQueries()) - $before;
		if ($used === 0) {
			self::markTestSkipped('Query logger inactive on this instance.');
		}
		self::assertLessThanOrEqual(
			$budget,
			$used,
			"getBalancesForEmployees() used $used queries; the budget is $budget. "
			. 'If the extra query is deliberate, raise tests/Integration/base-query-count.txt in the same change.',
		);
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return array<string,mixed>
	 */
	private function annualRow(array $rows): array {
		foreach ($rows as $row) {
			if (($row['typeKey'] ?? '') === 'annual') {
				return $row;
			}
		}
		self::fail('No annual row in the balance set');
	}

	/**
	 * @param list<array<string,mixed>> $single
	 * @param list<array<string,mixed>> $batch
	 */
	private static function assertSameBalances(array $single, array $batch, string $uid): void {
		$strip = static fn (array $rows): array => array_map(
			static fn (array $row): array => array_intersect_key($row, array_flip([
				'year', 'typeId', 'entitlement', 'used', 'pending', 'remaining', 'available',
			])),
			$rows,
		);
		self::assertSame($strip($single), $strip($batch), "balance paths disagree for $uid");
	}
}
