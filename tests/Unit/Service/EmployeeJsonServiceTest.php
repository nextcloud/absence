<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Service\EmployeeJsonService;
use PHPUnit\Framework\TestCase;

class EmployeeJsonServiceTest extends TestCase {
	private const ENTRIES = [
		['user' => 'robin', 'name' => 'Robin Sämple', 'region' => 'DE-BY', 'start' => '2024-03-01', 'end' => null, 'contract' => [
			['from' => '2024-03-01', 'weekdays' => [1, 2, 3, 4, 5], 'percent' => 100],
			['from' => '2026-07-01', 'weekdays' => [1, 2, 3, 4], 'percent' => null],
		]],
		['user' => 'sam@example.com', 'name' => 'Sam "the [brace]" Example', 'region' => null, 'start' => null, 'end' => null, 'contract' => []],
	];

	public function testTheTextReadsBackAsTheSameList(): void {
		$text = EmployeeJsonService::format(self::ENTRIES);

		self::assertSame(self::ENTRIES, json_decode($text, true, 8, JSON_THROW_ON_ERROR));
		self::assertStringContainsString("\t\t\t{ \"from\": \"2026-07-01\", \"weekdays\": [1, 2, 3, 4], \"percent\": null }\n", $text);
		self::assertSame("[]\n", EmployeeJsonService::format([]));
	}

	public function testEachEntryIsFoundOnItsLine(): void {
		self::assertSame([2, 13], EmployeeJsonService::entryLines(EmployeeJsonService::format(self::ENTRIES)));
		// Brackets and escaped quotes inside strings are text
		self::assertSame([1, 1, 3], EmployeeJsonService::entryLines("[{\"user\": \"a\\\"[{\"}, {\"user\": \"b]\"},\n\n {}]"));
		self::assertSame([], EmployeeJsonService::entryLines('[]'));
	}
}
