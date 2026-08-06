<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Service\ClockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The whole point of ClockService is that a day boundary is answered in the
 * right timezone, so the tests pin an instant that falls on a different
 * calendar day depending on who is asking.
 */
class ClockServiceTest extends TestCase {
	private function clock(int $timestamp, string $userZone, string $serverZone = 'UTC'): ClockService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($timestamp);

		$zones = $this->createMock(IDateTimeZone::class);
		$zones->method('getTimeZone')->willReturn(new \DateTimeZone($userZone));
		$zones->method('getDefaultTimeZone')->willReturn(new \DateTimeZone($serverZone));

		return new ClockService($time, $zones);
	}

	/** 2026-01-01 22:00 UTC — already the 2nd in Auckland (UTC+13). */
	private const NEW_YEARS_EVENING_UTC = 1767304800;

	public function testUserAheadOfUtcSeesTheNextDay(): void {
		$clock = $this->clock(self::NEW_YEARS_EVENING_UTC, 'Pacific/Auckland');

		self::assertSame('2026-01-02', $clock->userToday(), 'it is already tomorrow in Auckland');
		self::assertSame('2026-01-01', $clock->serverToday(), 'the server is still on UTC');
	}

	public function testUserBehindUtcSeesThePreviousDay(): void {
		// 2026-01-01 02:00 UTC — still New Year's Eve in Los Angeles (UTC-8)
		$clock = $this->clock(1767232800, 'America/Los_Angeles');

		self::assertSame('2025-12-31', $clock->userToday());
		self::assertSame('2026-01-01', $clock->serverToday());
	}

	/**
	 * The year boundary is the case that silently misfiles leave: a request made
	 * in the first hours of January in Auckland must count against the new year.
	 */
	public function testYearFollowsTheUsersOwnCalendar(): void {
		$clock = $this->clock(self::NEW_YEARS_EVENING_UTC, 'Pacific/Auckland');

		self::assertSame(2026, $clock->userYear());
		self::assertSame(2026, $clock->serverYear());

		$stillLastYear = $this->clock(1767232800, 'America/Los_Angeles');
		self::assertSame(2025, $stillLastYear->userYear(), 'not yet 2026 in Los Angeles');
		self::assertSame(2026, $stillLastYear->serverYear());
	}

	public function testServerBoundaryUsesTheConfiguredZoneNotUtc(): void {
		// 2026-01-01 23:30 UTC is already the 2nd in Berlin (UTC+1)
		$clock = $this->clock(1767310200, 'UTC', 'Europe/Berlin');

		self::assertSame('2026-01-02', $clock->serverToday());
		self::assertSame('2026-01-01', $clock->userToday());
	}

	public function testAUserInTheServerZoneAgreesWithIt(): void {
		$clock = $this->clock(self::NEW_YEARS_EVENING_UTC, 'Europe/Berlin', 'Europe/Berlin');

		self::assertSame($clock->serverToday(), $clock->userToday());
		self::assertSame($clock->serverYear(), $clock->userYear());
	}

	public function testNowKeepsTheInstantAndOnlyChangesTheZone(): void {
		$clock = $this->clock(self::NEW_YEARS_EVENING_UTC, 'Pacific/Auckland');

		self::assertSame(self::NEW_YEARS_EVENING_UTC, $clock->userNow()->getTimestamp());
		self::assertSame(self::NEW_YEARS_EVENING_UTC, $clock->serverNow()->getTimestamp());
		self::assertSame('Pacific/Auckland', $clock->userNow()->getTimezone()->getName());
	}
}
