<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

/**
 * The annual entitlement someone's contract would give them for a year (§6.6):
 * the default, for the months they are employed and the days a week they work.
 * A suggestion for HR, never applied by itself.
 *
 * @psalm-import-type Contract from EmployeeService
 */
class ProRataService {
	/** The day of the month that decides whether the month counts */
	private const KEY_DAY = 15;
	private const FULL_WEEK = 5;

	public function __construct(
		private EmployeeService $employees,
		private ConfigService $config,
	) {
	}

	/**
	 * @return array{days: float, fullDays: float, months: int, daysPerWeek: float}
	 */
	public function suggest(string $uid, int $year): array {
		return self::compute($this->config->getDefaultEntitlement(), $year, $this->employees->getContract($uid));
	}

	/**
	 * A month counts when the person is employed on its 15th, by the days a
	 * week they work on that day (5 without a working pattern, and never more
	 * than 5). The result is rounded up to the next half day.
	 *
	 * @param Contract $contract
	 * @return array{days: float, fullDays: float, months: int, daysPerWeek: float} the
	 *                                                                              suggestion, what a full year at full time gives, the months that
	 *                                                                              count and the average days a week over them
	 */
	public static function compute(float $fullDays, int $year, array $contract): array {
		$periods = $contract['periods'];
		usort($periods, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);
		$months = 0;
		$weekdays = 0;
		for ($month = 1; $month <= 12; $month++) {
			$day = sprintf('%04d-%02d-%02d', $year, $month, self::KEY_DAY);
			if (($contract['start'] !== null && $contract['start'] > $day) || ($contract['end'] !== null && $contract['end'] < $day)) {
				continue;
			}
			$inForce = self::FULL_WEEK;
			foreach ($periods as $period) {
				if ($period['from'] <= $day) {
					$inForce = min(self::FULL_WEEK, count($period['weekdays']));
				}
			}
			$months++;
			$weekdays += $inForce;
		}
		$exact = $fullDays * (float)$weekdays / (float)(12 * self::FULL_WEEK);
		return [
			// rounded first, so that 17.5 does not become 18 over a float's last digit
			'days' => ceil(round($exact * 2.0, 6)) / 2.0,
			'fullDays' => $fullDays,
			'months' => $months,
			'daysPerWeek' => $months === 0 ? 0.0 : round((float)$weekdays / (float)$months, 1),
		];
	}
}
