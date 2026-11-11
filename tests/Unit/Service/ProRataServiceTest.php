<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Service\ProRataService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProRataServiceTest extends TestCase {
	private const MON_TO_THU = [1, 2, 3, 4];
	private const MON_TO_FRI = [1, 2, 3, 4, 5];

	/**
	 * @return array<string, array{array{start: ?string, end: ?string, periods: list<array{from: string, weekdays: list<int>, percent: ?int}>}, float, int, float}>
	 */
	public static function contracts(): array {
		$period = static fn (string $from, array $weekdays): array => ['from' => $from, 'weekdays' => $weekdays, 'percent' => null];
		return [
			'no contract data: the full default' => [['start' => null, 'end' => null, 'periods' => []], 28.0, 12, 5.0],
			'started in an earlier year' => [['start' => '2020-03-01', 'end' => null, 'periods' => []], 28.0, 12, 5.0],
			// May to December: 28 × 8 / 12 = 18.67
			'starts on the 15th: the month counts' => [['start' => '2026-05-15', 'end' => null, 'periods' => []], 19.0, 8, 5.0],
			// June to December: 28 × 7 / 12 = 16.33
			'starts on the 16th: the month does not' => [['start' => '2026-05-16', 'end' => null, 'periods' => []], 16.5, 7, 5.0],
			// January to September: 28 × 9 / 12 = 21
			'leaves on the 15th: the month counts' => [['start' => null, 'end' => '2026-09-15', 'periods' => []], 21.0, 9, 5.0],
			'leaves on the 14th: the month does not' => [['start' => null, 'end' => '2026-09-14', 'periods' => []], 19.0, 8, 5.0],
			// 28 × 4 / 5 = 22.4
			'four days a week all year' => [['start' => null, 'end' => null, 'periods' => [$period('2025-01-01', self::MON_TO_THU)]], 22.5, 12, 4.0],
			// 6 months at 5 days, 6 at 4: 28 × (30 + 24) / 60 = 25.2
			'part time from July, periods in any order' => [['start' => null, 'end' => null, 'periods' => [$period('2026-07-01', self::MON_TO_THU), $period('2024-01-01', self::MON_TO_FRI)]], 25.5, 12, 4.5],
			'six days a week count as five' => [['start' => null, 'end' => null, 'periods' => [$period('2025-01-01', [1, 2, 3, 4, 5, 6])]], 28.0, 12, 5.0],
			'not employed in the year' => [['start' => '2027-01-01', 'end' => null, 'periods' => []], 0.0, 0, 0.0],
		];
	}

	/**
	 * @param array{start: ?string, end: ?string, periods: list<array{from: string, weekdays: list<int>, percent: ?int}>} $contract
	 */
	#[DataProvider('contracts')]
	public function testTheDefaultForTheMonthsEmployedAndTheDaysWorked(array $contract, float $days, int $months, float $daysPerWeek): void {
		self::assertSame(
			['days' => $days, 'fullDays' => 28.0, 'months' => $months, 'daysPerWeek' => $daysPerWeek],
			ProRataService::compute(28.0, 2026, $contract),
		);
	}

	public function testAnExactHalfDayIsNotRoundedUp(): void {
		// 30 × 7 / 12 = 17.5
		self::assertSame(17.5, ProRataService::compute(30.0, 2026, ['start' => '2026-06-01', 'end' => null, 'periods' => []])['days']);
	}
}
