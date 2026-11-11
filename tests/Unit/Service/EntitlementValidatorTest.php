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
			'half a day' => [['baseDays' => 27.5, 'carryOverDays' => 0.5], true],
			'one decimal that is not exact in binary' => [['baseDays' => 28.3, 'adjustmentDelta' => -0.1], true],
			'a negative adjustment' => [['manualAdjustment' => -3.0], true],
			'the largest value the column holds' => [['manualAdjustment' => -9999.9, 'baseDays' => 9999.9], true],
			'a note and no figure' => [['adjustmentNote' => 'Wedding'], true],
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
