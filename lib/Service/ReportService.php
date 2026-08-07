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
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * HR reporting: per-employee balances and company-wide trend aggregation (§13).
 */
class ReportService {
	use DateRangeTrait;

	/** Key of the leave type treated as sickness unless HR asks for another. */
	private const SICK_LEAVE_KEY = 'sick';

	public function __construct(
		private BalanceService $balanceService,
		private LeaveRequestMapper $requestMapper,
		private LeaveTypeMapper $leaveTypeMapper,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * Flat balances report for every employee (optionally within a group).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function balancesReport(int $year, ?string $group = null): array {
		$uids = $this->employeeUids($group);
		// One batched call, not one per employee — see getBalancesForEmployees().
		$balances = $this->balanceService->getBalancesForEmployees($uids, $year);
		$report = [];
		foreach ($uids as $uid) {
			$displayName = $this->displayName($uid);
			foreach ($balances[$uid] ?? [] as $row) {
				$report[] = array_merge($row, [
					'employeeUid' => $uid,
					'displayName' => $displayName,
				]);
			}
		}
		usort($report, static fn (array $a, array $b): int => [$a['displayName'], $a['sortOrder']] <=> [$b['displayName'], $b['sortOrder']]);
		return $report;
	}

	/**
	 * Company-wide trend aggregation for charts: approved working days per month and
	 * per leave type across a date range.
	 *
	 * @return array{byMonth:array<string,float>,byType:list<array<string,mixed>>,total:float}
	 */
	public function trends(string $from, string $to): array {
		[$from, $to] = $this->assertValidRange($from, $to);
		$byMonth = [];
		$byType = [];
		$total = 0.0;
		$typeMeta = [];
		foreach ($this->leaveTypeMapper->findAll() as $type) {
			$typeMeta[$type->getId()] = $type;
		}

		foreach ($this->requestMapper->findAllInRange($from, $to) as $request) {
			if ($request->getStatus() !== LeaveRequest::STATUS_APPROVED) {
				continue;
			}
			// Working days are entered manually; attribute the whole request to the
			// month/type in which it starts.
			$days = $request->getWorkingDays();
			$month = substr($request->getStartDate(), 0, 7);
			$byMonth[$month] = ($byMonth[$month] ?? 0.0) + $days;
			$byType[$request->getTypeId()] = ($byType[$request->getTypeId()] ?? 0.0) + $days;
			$total += $days;
		}
		ksort($byMonth);

		$byTypeList = [];
		foreach ($byType as $typeId => $days) {
			$type = $typeMeta[$typeId] ?? null;
			$byTypeList[] = [
				'typeId' => $typeId,
				'typeLabel' => $type?->getLabel() ?? (string)$typeId,
				'typeColor' => $type?->getColor() ?? '#888888',
				'typeIcon' => $type?->getIcon() ?? '',
				'days' => $days,
			];
		}

		return ['byMonth' => $byMonth, 'byType' => $byTypeList, 'total' => $total];
	}

	/**
	 * Sick-leave overview for HR (every employee, ranked by days lost).
	 *
	 * Which leave types count as sickness is not a flag on the type, so the
	 * default is the seeded 'sick' key and HR can pass an explicit type instead.
	 * The types actually aggregated come back with the report, so the UI can name
	 * them rather than implying "sickness" is a fixed concept — and if an instance
	 * has no matching type, `types` is empty and the caller can say so instead of
	 * showing a table of zeroes.
	 *
	 * Days are attributed to the year the leave *starts*, matching how balances
	 * are computed, and only approved leave is counted: a pending record is not
	 * yet a fact about someone's health.
	 *
	 * @param int $year calendar year to report on
	 * @param ?string $group restrict to a Nextcloud group
	 * @param ?int $typeId count this leave type instead of the default
	 * @return array{
	 *     year: int,
	 *     types: list<array{id:int,key:string,label:string,color:string,icon:string}>,
	 *     rows: list<array<string,mixed>>,
	 *     totals: array{employees:int,affected:int,days:float,episodes:int}
	 * }
	 */
	public function sickLeaveReport(int $year, ?string $group = null, ?int $typeId = null): array {
		$types = $this->sickLeaveTypes($typeId);
		$typeIds = array_map(static fn (array $t): int => $t['id'], $types);
		$uids = $this->employeeUids($group);

		$rows = [];
		foreach ($uids as $uid) {
			$rows[$uid] = [
				'employeeUid' => $uid,
				'displayName' => $this->displayName($uid),
				'days' => 0.0,
				'episodes' => 0,
				'longestEpisode' => 0.0,
				'lastDate' => null,
			];
		}

		if ($types !== [] && $uids !== []) {
			$requests = $this->requestMapper->findForEmployeesInRange(
				$uids,
				sprintf('%04d-01-01', $year),
				sprintf('%04d-12-31', $year),
				LeaveRequest::USED_STATUSES,
			);
			foreach ($requests as $request) {
				if (!in_array($request->getTypeId(), $typeIds, true)) {
					continue;
				}
				// the range query also returns leave that merely overlaps the year
				if ((int)substr($request->getStartDate(), 0, 4) !== $year) {
					continue;
				}
				$uid = $request->getEmployeeUid();
				if (!isset($rows[$uid])) {
					// left the company or outside the requested group
					continue;
				}
				$days = (float)$request->getWorkingDays();
				$rows[$uid]['days'] += $days;
				$rows[$uid]['episodes']++;
				$rows[$uid]['longestEpisode'] = max($rows[$uid]['longestEpisode'], $days);
				$rows[$uid]['lastDate'] = max((string)$rows[$uid]['lastDate'], $request->getEndDate());
			}
		}

		$rows = array_values($rows);
		// most days lost first; ties by name so the order is stable between loads
		usort($rows, static fn (array $a, array $b): int
			=> [$b['days'], $a['displayName']] <=> [$a['days'], $b['displayName']]);

		$affected = array_values(array_filter($rows, static fn (array $r): bool => $r['days'] > 0));

		return [
			'year' => $year,
			'types' => $types,
			'rows' => $rows,
			'totals' => [
				'employees' => count($rows),
				'affected' => count($affected),
				// cast: array_sum() of an empty column is int 0, and the client
				// should not see the type change with the data
				'days' => (float)array_sum(array_column($rows, 'days')),
				'episodes' => (int)array_sum(array_column($rows, 'episodes')),
			],
		];
	}

	/**
	 * @return list<array{id:int,key:string,label:string,color:string,icon:string}>
	 */
	private function sickLeaveTypes(?int $typeId): array {
		$types = [];
		foreach ($this->leaveTypeMapper->findAll() as $type) {
			$matches = $typeId !== null
				? $type->getId() === $typeId
				: $type->getKey() === self::SICK_LEAVE_KEY;
			if ($matches) {
				$types[] = [
					'id' => $type->getId(),
					'key' => $type->getKey(),
					'label' => $type->getLabel(),
					'color' => $type->getColor(),
					'icon' => $type->getIcon(),
				];
			}
		}
		return $types;
	}

	private function employeeUids(?string $group): array {
		if ($group !== null && $group !== '') {
			$g = $this->groupManager->get($group);
			if ($g === null) {
				return [];
			}
			return array_map(static fn ($u) => $u->getUID(), $g->getUsers());
		}
		$uids = [];
		$this->userManager->callForAllUsers(static function ($user) use (&$uids): void {
			$uids[] = $user->getUID();
		});
		return $uids;
	}

	private function displayName(string $uid): string {
		$user = $this->userManager->get($uid);
		return $user !== null ? $user->getDisplayName() : $uid;
	}
}
