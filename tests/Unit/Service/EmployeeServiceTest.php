<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Service\EmployeeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The share of a full-time year someone works (§6.4), applied to 30 days.
 */
class EmployeeServiceTest extends TestCase {
	private const FULL_TIME = 30.0;

	public function testNoContractDataKeepsTheFullAllowance(): void {
		self::assertNull(EmployeeService::yearShare(['start' => null, 'end' => null, 'periods' => []], 2026));
	}

	/**
	 * @param list<array{from: string, weekdays: list<int>, percent: ?int}> $periods
	 */
	#[DataProvider('contracts')]
	public function testTheYearShare(?string $start, ?string $end, array $periods, float $days): void {
		$share = EmployeeService::yearShare(['start' => $start, 'end' => $end, 'periods' => $periods], 2026);

		self::assertNotNull($share);
		self::assertSame($days, round(self::FULL_TIME * $share['weekdayMonths'] / EmployeeService::FULL_YEAR));
	}

	/**
	 * @return array<string, array{?string, ?string, list<array{from: string, weekdays: list<int>, percent: ?int}>, float}>
	 */
	public static function contracts(): array {
		$fullWeek = [1, 2, 3, 4, 5];
		return [
			'full time all year' => ['2019-01-07', null, [], 30.0],
			'three days a week, 50%' => [null, null, [['from' => '2020-01-01', 'weekdays' => [1, 2, 3], 'percent' => 50]], 18.0],
			'five days at 50% keeps 30' => [null, null, [['from' => '2020-01-01', 'weekdays' => $fullWeek, 'percent' => 50]], 30.0],
			'four days from July' => [null, null, [
				['from' => '2024-03-01', 'weekdays' => $fullWeek, 'percent' => 100],
				['from' => '2026-07-01', 'weekdays' => [1, 2, 3, 4], 'percent' => 80],
			], 27.0],
			'joins on the 15th: the month counts' => ['2026-03-15', null, [], 25.0],
			'joins on the 16th: half a day rounds up' => ['2026-03-16', null, [], 23.0],
			'leaves on the 15th: the month counts' => [null, '2026-09-15', [], 23.0],
			'leaves on the 14th' => [null, '2026-09-14', [], 20.0],
			'joins on 1 September' => ['2026-09-01', null, [], 10.0],
			'left last year' => [null, '2025-12-31', [], 0.0],
			'change on the 20th counts from the next month' => [null, null, [['from' => '2026-03-20', 'weekdays' => [1, 2, 3, 4], 'percent' => 80]], 26.0],
		];
	}

	public function testTheSegmentsGroupMonthsWithTheSamePattern(): void {
		$share = EmployeeService::yearShare(['start' => '2026-03-01', 'end' => null, 'periods' => [
			['from' => '2026-07-01', 'weekdays' => [1, 2, 3, 4], 'percent' => 80],
		]], 2026);

		self::assertSame([
			['from' => 3, 'to' => 6, 'weekdays' => 5],
			['from' => 7, 'to' => 12, 'weekdays' => 4],
		], $share['segments'] ?? null);
	}
}
