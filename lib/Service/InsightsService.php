<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * The HR "Insights" tab (spec §15.7): diagnostic and forward-looking figures that
 * go beyond the descriptive reports — how well approval is working, disruptive
 * absence patterns (Bradford Factor), how much of their leave people actually
 * take, and the outstanding leave liability on the books.
 *
 * Everything is HR-only and computed from data the app already stores; the
 * controller gates it with {@see PermissionService::assertHr()}.
 */
class InsightsService {
	private const ANNUAL_KEY = 'annual';
	private const SICK_KEY = 'sick';
	/** A person not off in this many days is surfaced as a well-being nudge. */
	private const NEGLECTED_DAYS = 90;
	/** How many rows the ranked lists return — enough to act on, not a data dump. */
	private const TOP_N = 10;

	public function __construct(
		private LeaveRequestMapper $requestMapper,
		private LeaveTypeMapper $leaveTypeMapper,
		private BalanceService $balanceService,
		private EmployeeDirectory $employees,
		private ManagerResolver $managerResolver,
		private ConfigService $config,
		private ClockService $clock,
		private IUserManager $userManager,
		private IL10N $l,
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getInsights(int $year): array {
		$uids = $this->employees->listInGroup(null);
		$uidSet = array_fill_keys($uids, true);
		$teams = $this->buildTeams($uids);
		$balances = $this->balanceService->getBalancesForEmployees($uids, $year);

		// This year's leave, attributed by start year like every other report.
		$yearRequests = array_filter(
			$this->requestMapper->findAllInRange(sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)),
			fn (LeaveRequest $r): bool => isset($uidSet[$r->getEmployeeUid()]) && (int)substr($r->getStartDate(), 0, 4) === $year,
		);

		return [
			'year' => $year,
			'approval' => $this->approvalHealth($yearRequests),
			'bradford' => $this->bradford($yearRequests),
			'utilization' => $this->utilization($balances, $teams),
			'liability' => $this->liability($balances, $teams, $year),
		];
	}

	// --------------------------------------------------------- approval ----

	/**
	 * How long decisions take and how often they have to be escalated. Only leave
	 * that actually went to a manager counts — HR-recorded leave is booked without
	 * a decision, so it would report an instant, meaningless turnaround.
	 *
	 * @param LeaveRequest[] $requests
	 * @return array<string,mixed>
	 */
	private function approvalHealth(array $requests): array {
		$needsDecision = array_filter($requests, static fn (LeaveRequest $r): bool => $r->getManagerUid() !== null);
		$overall = [];
		$escalated = 0;
		/** @var array<string,array{hours:float[],escalated:int,total:int}> $byManager */
		$byManager = [];
		foreach ($needsDecision as $r) {
			$manager = (string)$r->getManagerUid();
			$byManager[$manager] ??= ['hours' => [], 'escalated' => 0, 'total' => 0];
			$byManager[$manager]['total']++;
			if ($r->getEscalated()) {
				$escalated++;
				$byManager[$manager]['escalated']++;
			}
			$hours = $this->decisionHours($r);
			if ($hours !== null) {
				$overall[] = $hours;
				$byManager[$manager]['hours'][] = $hours;
			}
		}

		$managers = [];
		foreach ($byManager as $uid => $m) {
			$managers[] = [
				'managerUid' => $uid,
				'name' => $this->displayName($uid),
				'decided' => count($m['hours']),
				'escalated' => $m['escalated'],
				'medianHours' => $m['hours'] === [] ? null : $this->median($m['hours']),
			];
		}
		// Slowest median first — the whole point is to surface who to nudge; a
		// manager with no completed decisions sorts last.
		usort($managers, static fn (array $a, array $b): int => ($b['medianHours'] ?? -1) <=> ($a['medianHours'] ?? -1));

		$total = count($needsDecision);
		return [
			'decided' => count($overall),
			'medianHours' => $overall === [] ? null : $this->median($overall),
			'p95Hours' => $overall === [] ? null : $this->percentile($overall, 95),
			'escalationRate' => $total === 0 ? 0.0 : round($escalated / $total, 3),
			'escalatedCount' => $escalated,
			'needingDecision' => $total,
			'perManager' => array_slice($managers, 0, self::TOP_N),
		];
	}

	private function decisionHours(LeaveRequest $r): ?float {
		$decidedAt = $r->getDecidedAt();
		$createdAt = $r->getCreatedAt();
		if ($decidedAt === null) {
			return null;
		}
		$hours = ($decidedAt->getTimestamp() - $createdAt->getTimestamp()) / 3600;
		// A backfilled or clock-skewed row must not drag a median negative.
		return max(0.0, round($hours, 1));
	}

	// --------------------------------------------------------- Bradford ----

	/**
	 * The Bradford Factor (spells² × days) per employee over the year, from
	 * recorded sick leave. It weights frequent short absences far more heavily
	 * than a single long illness — the standard flag for a supportive check-in.
	 *
	 * @param LeaveRequest[] $requests
	 * @return list<array<string,mixed>>
	 */
	private function bradford(array $requests): array {
		$sickIds = $this->sickTypeIds();
		if ($sickIds === []) {
			return [];
		}
		/** @var array<string,array{spells:int,days:float}> $byEmployee */
		$byEmployee = [];
		foreach ($requests as $r) {
			if (!in_array($r->getStatus(), LeaveRequest::USED_STATUSES, true)) {
				continue;
			}
			if (!in_array($r->getTypeId(), $sickIds, true)) {
				continue;
			}
			$uid = $r->getEmployeeUid();
			$byEmployee[$uid] ??= ['spells' => 0, 'days' => 0.0];
			$byEmployee[$uid]['spells']++;
			$byEmployee[$uid]['days'] += (float)$r->getWorkingDays();
		}

		$rows = [];
		foreach ($byEmployee as $uid => $b) {
			$spells = (float)$b['spells'];
			$rows[] = [
				'employeeUid' => $uid,
				'name' => $this->displayName($uid),
				'spells' => $b['spells'],
				'days' => round($b['days'], 1),
				'score' => (int)round($spells * $spells * $b['days']),
			];
		}
		usort($rows, static fn (array $a, array $b): int => [$b['score'], $b['spells']] <=> [$a['score'], $a['spells']]);
		return array_slice($rows, 0, self::TOP_N);
	}

	// ------------------------------------------------------ utilization ----

	/**
	 * How much annual leave people actually take (used ÷ entitlement), company- and
	 * team-wide, plus a watchlist of people who have not been off in a while —
	 * under-use is the burnout signal the headline reports miss.
	 *
	 * @param array<string,list<array<string,mixed>>> $balances
	 * @param list<array{managerUid:?string,name:string,uids:string[]}> $teams
	 * @return array<string,mixed>
	 */
	private function utilization(array $balances, array $teams): array {
		$company = $this->utilizationFor(array_keys($balances), $balances);
		$perTeam = [];
		foreach ($teams as $team) {
			$rate = $this->utilizationFor($team['uids'], $balances);
			if ($rate['entitlement'] > 0) {
				$perTeam[] = ['name' => $team['name']] + $rate;
			}
		}
		usort($perTeam, static fn (array $a, array $b): int => $a['rate'] <=> $b['rate']);

		return [
			'company' => $company,
			'perTeam' => $perTeam,
			'neglected' => $this->neglected(array_keys($balances)),
		];
	}

	/**
	 * @param string[] $uids
	 * @param array<string,list<array<string,mixed>>> $balances
	 * @return array{used:float,entitlement:float,rate:float}
	 */
	private function utilizationFor(array $uids, array $balances): array {
		$used = 0.0;
		$entitlement = 0.0;
		foreach ($uids as $uid) {
			$row = $this->annualRow($balances[$uid] ?? []);
			if ($row === null || $row['entitlement'] === null) {
				continue;
			}
			$used += (float)$row['used'];
			$entitlement += (float)$row['entitlement'];
		}
		return [
			'used' => round($used, 1),
			'entitlement' => round($entitlement, 1),
			'rate' => $entitlement > 0 ? round($used / $entitlement, 3) : 0.0,
		];
	}

	/**
	 * People whose most recent actual break ended more than {@see NEGLECTED_DAYS}
	 * ago (or who have not been off at all within the last year). Sick leave does
	 * not count as a break — the point is rest, not being unwell.
	 *
	 * @param string[] $uids
	 * @return list<array<string,mixed>>
	 */
	private function neglected(array $uids): array {
		$uidSet = array_fill_keys($uids, true);
		$today = $this->clock->userToday();
		$windowStart = (new \DateTimeImmutable($today))->modify('-365 days')->format('Y-m-d');
		$sickIds = $this->sickTypeIds();
		/** @var array<string,string> $lastEnd employee => last break end (<= today) */
		$lastEnd = [];
		foreach ($this->requestMapper->findAllInRange($windowStart, $today) as $r) {
			if ($r->getStatus() !== LeaveRequest::STATUS_APPROVED) {
				continue;
			}
			if (!isset($uidSet[$r->getEmployeeUid()]) || in_array($r->getTypeId(), $sickIds, true)) {
				continue;
			}
			if ($r->getStartDate() > $today) {
				continue; // a booked future break is not rest already taken
			}
			$end = min($r->getEndDate(), $today);
			$uid = $r->getEmployeeUid();
			if (!isset($lastEnd[$uid]) || $end > $lastEnd[$uid]) {
				$lastEnd[$uid] = $end;
			}
		}

		$rows = [];
		foreach ($uids as $uid) {
			$last = $lastEnd[$uid] ?? null;
			$daysSince = $last === null
				? null
				: (int)(new \DateTimeImmutable($last))->diff(new \DateTimeImmutable($today))->days;
			if ($last !== null && $daysSince < self::NEGLECTED_DAYS) {
				continue;
			}
			$rows[] = [
				'employeeUid' => $uid,
				'name' => $this->displayName($uid),
				'lastLeaveDate' => $last,
				'daysSince' => $daysSince, // null = none within the last year
			];
		}
		// Longest-neglected first; "never" (null) sorts to the very top.
		usort($rows, static fn (array $a, array $b): int => ($b['daysSince'] ?? PHP_INT_MAX) <=> ($a['daysSince'] ?? PHP_INT_MAX));
		return array_slice($rows, 0, self::TOP_N);
	}

	// -------------------------------------------------------- liability ----

	/**
	 * Outstanding annual-leave entitlement (accrued but not taken) — a real
	 * balance-sheet liability — plus the carry-over that will be lost at the
	 * configured expiry date if it is not used first.
	 *
	 * @param array<string,list<array<string,mixed>>> $balances
	 * @param list<array{managerUid:?string,name:string,uids:string[]}> $teams
	 * @return array<string,mixed>
	 */
	private function liability(array $balances, array $teams, int $year): array {
		$company = $this->liabilityFor(array_keys($balances), $balances);
		$perTeam = [];
		foreach ($teams as $team) {
			$row = $this->liabilityFor($team['uids'], $balances);
			if ($row['outstanding'] > 0) {
				$perTeam[] = ['name' => $team['name']] + $row;
			}
		}
		usort($perTeam, static fn (array $a, array $b): int => $b['outstanding'] <=> $a['outstanding']);

		return [
			'outstanding' => $company['outstanding'],
			'carryOverExposure' => $company['carryOverExposure'],
			'expiryDate' => $this->carryOverExpiryDate($year),
			'perTeam' => $perTeam,
		];
	}

	/**
	 * @param string[] $uids
	 * @param array<string,list<array<string,mixed>>> $balances
	 * @return array{outstanding:float,carryOverExposure:float}
	 */
	private function liabilityFor(array $uids, array $balances): array {
		$outstanding = 0.0;
		$carryOver = 0.0;
		foreach ($uids as $uid) {
			$row = $this->annualRow($balances[$uid] ?? []);
			if ($row === null || $row['remaining'] === null) {
				continue;
			}
			$remaining = max(0.0, (float)$row['remaining']);
			$outstanding += $remaining;
			// The at-risk carry-over is what is still unspent, capped at the
			// carry-over that was granted — you cannot lose more than you carried.
			$carryOver += min((float)$row['carryOverDays'], $remaining);
		}
		return [
			'outstanding' => round($outstanding, 1),
			'carryOverExposure' => round($carryOver, 1),
		];
	}

	/** The date this year's carry-over expires, or null when nothing expires. */
	private function carryOverExpiryDate(int $year): ?string {
		if ($this->config->getCarryOverPolicy() === ConfigService::CARRYOVER_NONE) {
			return null;
		}
		$expiry = $this->config->getCarryOverExpiry(); // 'MM-DD' or ''
		if ($expiry === '' || !preg_match('/^\d{2}-\d{2}$/', $expiry)) {
			return null;
		}
		// Carry-over from `year` is used in `year + 1` and expires there.
		return sprintf('%04d-%s', $year + 1, $expiry);
	}

	// ---------------------------------------------------------- helpers ----

	/**
	 * Group every employee under their manager (§2.1) so the company splits into
	 * the teams HR actually manages by. People with no manager form one group.
	 *
	 * @param string[] $uids
	 * @return list<array{managerUid:?string,name:string,uids:string[]}>
	 */
	private function buildTeams(array $uids): array {
		/** @var array<string,string[]> $byManager */
		$byManager = [];
		foreach ($uids as $uid) {
			$manager = $this->managerResolver->getManagerUid($uid) ?? '';
			$byManager[$manager][] = $uid;
		}
		$teams = [];
		foreach ($byManager as $manager => $members) {
			$teams[] = [
				'managerUid' => $manager === '' ? null : $manager,
				'name' => $manager === ''
					? $this->l->t('No manager')
					: $this->l->t("%s's team", [$this->displayName($manager)]),
				'uids' => $members,
			];
		}
		usort($teams, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);
		return $teams;
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return array<string,mixed>|null
	 */
	private function annualRow(array $rows): ?array {
		foreach ($rows as $row) {
			if ($row['typeKey'] === self::ANNUAL_KEY) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * @return int[]
	 */
	private function sickTypeIds(): array {
		$ids = [];
		foreach ($this->leaveTypeMapper->findAll() as $type) {
			if ($type->getKey() === self::SICK_KEY) {
				$ids[] = $type->getId();
			}
		}
		return $ids;
	}

	/**
	 * @param float[] $values
	 */
	private function median(array $values): float {
		return $this->percentile($values, 50);
	}

	/**
	 * The p-th percentile by linear interpolation. Small n (a handful of decisions
	 * per manager) is the norm here, so an exact method beats a streaming estimate.
	 *
	 * @param float[] $values
	 */
	private function percentile(array $values, float $p): float {
		sort($values);
		$n = count($values);
		if ($n === 1) {
			return round($values[0], 1);
		}
		$rank = ($p / 100.0) * (float)($n - 1);
		$low = (int)floor($rank);
		$high = (int)ceil($rank);
		$value = $values[$low] + ($rank - (float)$low) * ($values[$high] - $values[$low]);
		return round($value, 1);
	}

	private function displayName(string $uid): string {
		$user = $this->userManager->get($uid);
		return $user !== null ? $user->getDisplayName() : $uid;
	}
}
