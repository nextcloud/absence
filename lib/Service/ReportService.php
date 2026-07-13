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
		$report = [];
		foreach ($this->employeeUids($group) as $uid) {
			$displayName = $this->displayName($uid);
			foreach ($this->balanceService->getBalance($uid, $year)['balances'] as $row) {
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
	 * @return string[]
	 */
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
