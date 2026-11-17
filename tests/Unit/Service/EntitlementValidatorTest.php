<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\EntitlementValidator;
use OCA\Absence\Tests\Unit\L10nMockTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EntitlementValidatorTest extends TestCase {
	use L10nMockTrait;

	/**
	 * @return array<string,array{array<string,mixed>,bool}>
	 */
	public static function figures(): array {
		return [
			'whole days' => [['baseDays' => 28.0], true],
			'whole days sent as text' => [['baseDays' => '28', 'carryOverDays' => '3.0'], true],
			'a negative adjustment' => [['manualAdjustment' => -3.0], true],
			'the largest whole values the column holds' => [['manualAdjustment' => -9999.0, 'baseDays' => 9999.0], true],
			'a note and no figure' => [['adjustmentNote' => 'Wedding'], true],
			'half a day of base' => [['baseDays' => 27.5], false],
			'half a day carried over' => [['carryOverDays' => 0.5], false],
			'half a day set as adjustment' => [['manualAdjustment' => 1.5], false],
			'one decimal in a correction' => [['adjustmentDelta' => -0.1], false],
			'the largest value with a decimal' => [['baseDays' => 9999.9], false],
			'two decimals' => [['baseDays' => 28.25], false],
			'two decimals in a correction' => [['adjustmentDelta' => 0.05], false],
			'more than the column holds' => [['carryOverDays' => 10000.0], false],
			'less than the column holds' => [['manualAdjustment' => -10000.0], false],
			'a negative base' => [['baseDays' => -1.0], false],
			'not a finite number' => [['baseDays' => INF], false],
		];
	}

	/**
	 * @param array<string,mixed> $data
	 */
	#[DataProvider('figures')]
	public function testFigures(array $data, bool $valid): void {
		if (!$valid) {
			$this->expectException(ValidationException::class);
		}
		(new EntitlementValidator($this->l10nMock()))->assertFigures($data);
		$this->addToAssertionCount(1);
	}

	/**
	 * @return array<int,array{int,bool}>
	 */
	public static function years(): array {
		return [[1999, false], [2000, true], [2026, true], [2100, true], [2101, false]];
	}

	#[DataProvider('years')]
	public function testYear(int $year, bool $valid): void {
		if (!$valid) {
			$this->expectException(ValidationException::class);
		}
		(new EntitlementValidator($this->l10nMock()))->assertYear($year);
		$this->addToAssertionCount(1);
	}
}
