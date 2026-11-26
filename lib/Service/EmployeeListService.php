<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Exception\ValidationException;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * The HR employees list (§13): everyone with their region code, contract,
 * manager, annual days and sick days for a year, and what HR still has to
 * fill in: the region code, the working pattern and the start date. Loaded
 * in a fixed number of queries, however many people there are.
 *
 * @psalm-import-type Contract from EmployeeService
 * @psalm-import-type Period from EmployeeService
 */
class EmployeeListService {
	public const MISSING_REGION = 'region';
	public const MISSING_CONTRACT = 'contract';
	public const MISSING_START = 'start';

	private const ANNUAL = 'annual';
	private const FULL_WEEK = 5;
	private const FULL_TIME = 100;

	public function __construct(
		private EmployeeDirectory $directory,
		private EmployeeService $employees,
		private BalanceService $balances,
		private ManagerResolver $managers,
		private ConfigService $config,
		private ReportService $reports,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IAppManager $appManager,
		private ClockService $clock,
		private HolidayService $holidays,
		private EntitlementValidator $validator,
	) {
	}

	/**
	 * @return array{year: int, employeesGroup: string, employeesGroupName: string, contacts: bool, rows: list<array<string, mixed>>}
	 * @throws ValidationException
	 */
	public function list(?int $year): array {
		$year ??= $this->clock->userYear();
		$this->validator->assertYear($year);
		$group = $this->config->getEmployeesGroup();
		return [
			'year' => $year,
			'employeesGroup' => $group,
			'employeesGroupName' => $group === '' ? '' : ($this->groupManager->get($group)?->getDisplayName() ?? $group),
			'contacts' => $this->appManager->isEnabledForUser('contacts'),
			'rows' => $this->rows($this->directory->listAll(), $year),
		];
	}

	/**
	 * @param list<string> $uids
	 * @return list<array<string, mixed>>
	 */
	public function rows(array $uids, int $year): array {
		if ($uids === []) {
			return [];
		}
		$this->employees->preload($uids);
		$balances = $this->balances->getBalancesForEmployees($uids, $year);
		$managers = $this->managers->getManagerUidsOf($uids);
		$regions = $this->config->getRegionCodes();
		$sick = $this->reports->sickDays($uids, $year);
		$today = $this->clock->userToday();

		$rows = [];
		foreach ($uids as $uid) {
			$contract = $this->employees->getContract($uid);
			$managerUid = $managers[$uid] ?? null;
			$region = $regions[$uid] ?? '';
			$counting = array_values(array_filter($balances[$uid] ?? [], static fn (array $row): bool => $row['countsAgainstBalance'] === true));
			$annual = null;
			foreach ($counting as $row) {
				if ($row['typeKey'] === self::ANNUAL) {
					$annual = $row;
				}
			}
			$current = self::currentPeriod($contract, $today);
			// Somebody who left keeps their records here, but nothing is missing
			// any more: their row never counts towards the data HR has to fill in.
			$disabled = $this->directory->isDisabled($uid);
			$missing = $disabled ? [] : array_keys(array_filter([
				self::MISSING_REGION => $this->holidays->needsRegion($region),
				self::MISSING_CONTRACT => $contract['periods'] === [],
				self::MISSING_START => $contract['start'] === null,
			]));
			$rows[] = [
				'uid' => $uid,
				'displayName' => $this->displayName($uid),
				'regionCode' => $region,
				'regionIncomplete' => !$disabled && $region !== '' && $this->holidays->needsRegion($region),
				'disabled' => $disabled,
				'contract' => $contract,
				'current' => $current,
				'manager' => $managerUid === null ? null : ['uid' => $managerUid, 'displayName' => $this->displayName($managerUid)],
				'annual' => $annual,
				'balances' => $counting,
				'sickDays' => $sick[$uid] ?? 0.0,
				'missing' => $missing,
				'partTime' => $current !== null && (count($current['weekdays']) < self::FULL_WEEK || ($current['percent'] ?? self::FULL_TIME) < self::FULL_TIME),
				'startsOrLeaves' => self::inYear($contract['start'], $year) || self::inYear($contract['end'], $year),
			];
		}
		usort($rows, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']) ?: strcmp($a['uid'], $b['uid']));
		return $rows;
	}

	/**
	 * The working pattern in force on a day, or the first one while none has
	 * started yet.
	 *
	 * @param Contract $contract
	 * @return Period|null
	 */
	public static function currentPeriod(array $contract, string $day): ?array {
		$current = $contract['periods'][0] ?? null;
		foreach ($contract['periods'] as $period) {
			if ($period['from'] <= $day) {
				$current = $period;
			}
		}
		return $current;
	}

	private static function inYear(?string $date, int $year): bool {
		return $date !== null && (int)substr($date, 0, 4) === $year;
	}

	private function displayName(string $uid): string {
		return $this->userManager->get($uid)?->getDisplayName() ?? $uid;
	}
}
