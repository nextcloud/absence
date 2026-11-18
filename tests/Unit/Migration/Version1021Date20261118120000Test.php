<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Migration;

use OCA\Absence\Migration\Version1021Date20261118120000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Version1021Date20261118120000Test extends TestCase {
	public static function details(): array {
		$created = 'Annual leave, 2026-03-02 – 2026-03-06 (5 days)';
		return [
			'a request with its reason' => [
				'request_created',
				$created . ' · Reason: “Migraine; MRI on Monday” · Recorded by HR',
				$created . ' · Recorded by HR',
			],
			'a request with a reason in German quotes' => [
				'request_created',
				$created . ' · Reason: “Termin „Arzt“ um 9”',
				$created,
			],
			'a request routed to HR before the em dashes went' => [
				'request_created',
				$created . " · Reason: “Wedding” · No line manager \u{2014} routed to HR",
				$created . " · No line manager \u{2014} routed to HR",
			],
			'imported leave with its note' => [
				'request_imported',
				'Annual leave, 2025-03-03 – 2025-03-07 (5 days) · Note: “Ski trip” · Imported by HR',
				'Annual leave, 2025-03-03 – 2025-03-07 (5 days) · Imported by HR',
			],
			'an edit that adds a reason' => [
				'request_updated',
				'Working days 3 days → 5 days (+2 days); Reason: “Wedding”; Replacement: Robin Vale',
				'Working days 3 days → 5 days (+2 days); Reason added; Replacement: Robin Vale',
			],
			'an HR edit that changes the reason' => [
				'request_hr_edited',
				'Type Annual leave → Special leave; Reason “Wedding” → “Wedding → abroad”',
				'Type Annual leave → Special leave; Reason changed',
			],
		];
	}

	#[DataProvider('details')]
	public function testTheTextGoesToHrAndTheRestStays(string $eventType, string $detail, string $neutral): void {
		self::assertSame([$neutral, $detail], Version1021Date20261118120000::split($eventType, $detail));
	}

	public static function textOnly(): array {
		return [
			['comment_added', 'MRI on Monday'],
			['request_approved', 'Get well soon'],
			['request_rejected', 'Too thin that week'],
			['withdrawal_rejected', 'Nobody to cover'],
		];
	}

	#[DataProvider('textOnly')]
	public function testAnEntryThatIsAllTextGoesToHrWhole(string $eventType, string $detail): void {
		self::assertSame([null, $detail], Version1021Date20261118120000::split($eventType, $detail));
	}

	public static function withoutText(): array {
		return [
			['request_created', 'Annual leave, 2026-03-02 (1 day) · Automatically approved'],
			['request_created', 'Recorded by HR'],
			['request_updated', 'Changed to 2026-03-02 – 2026-03-04'],
			['request_hr_edited', 'Dates 2026-03-02 – 2026-03-04 → 2026-03-02 – 2026-03-05; Reason cleared'],
			['request_imported', 'Sick leave, 2025-05-06 (1 day) · Imported by HR'],
			['request_edited_superseding', 'Edit of approved leave, pending re-approval'],
			['disability_changed', 'Set'],
		];
	}

	#[DataProvider('withoutText')]
	public function testAnEntryWithoutTextStaysAsItIs(string $eventType, string $detail): void {
		self::assertSame([$detail, null], Version1021Date20261118120000::split($eventType, $detail));
	}

	public static function ambiguous(): array {
		return [
			'a closing quote in the reason' => ['request_created', 'Annual leave, 2026-03-02 (1 day) · Reason: “the ” sign, MRI” · Recorded by HR'],
			'a closing quote in the old reason' => ['request_updated', 'Reason “a ” b” → “c”'],
			'a closing quote in the new reason' => ['request_hr_edited', 'Reason “a” → “b ” c”; Replacement: Robin Vale'],
			'a reason that looks like a second one' => ['request_updated', 'Reason: “q” secret Reason “r” → “s”'],
			'a quote in a type label' => ['request_updated', 'Type “Special” leave → Annual leave'],
			'an unknown event' => ['request_archived', 'Archived by HR'],
		];
	}

	#[DataProvider('ambiguous')]
	public function testAnEntryThatCannotBeSplitGoesToHrWhole(string $eventType, string $detail): void {
		self::assertSame([null, $detail], Version1021Date20261118120000::split($eventType, $detail));
	}
}
