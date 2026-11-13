<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\DAV;

use OCA\Absence\DAV\CalendarPlugin;
use OCA\Absence\DAV\PersonalCalendar;
use OCA\Absence\Service\CalendarService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CalendarPluginTest extends TestCase {
	public function testAnEmployeeGetsTheirCalendar(): void {
		$calendar = $this->createMock(CalendarService::class);
		$calendar->expects(self::once())->method('hasPersonalCalendar')->with('alice')->willReturn(true);

		$found = (new CalendarPlugin($calendar))->fetchAllForCalendarHome('principals/users/alice');

		self::assertCount(1, $found);
		self::assertInstanceOf(PersonalCalendar::class, $found[0]);
		self::assertSame('principals/users/alice', $found[0]->getOwner());
	}

	public function testNoCalendarWhenTheServiceSaysNo(): void {
		$calendar = $this->createMock(CalendarService::class);
		$calendar->method('hasPersonalCalendar')->willReturn(false);

		self::assertSame([], (new CalendarPlugin($calendar))->fetchAllForCalendarHome('principals/users/alice'));
	}

	public static function otherPrincipals(): array {
		return [
			'system' => ['principals/system/absence'],
			'group' => ['principals/groups/staff'],
			'room' => ['principals/calendar-rooms/room1'],
			'empty uid' => ['principals/users/'],
		];
	}

	#[DataProvider('otherPrincipals')]
	public function testOnlyUserPrincipalsHaveOne(string $principalUri): void {
		$calendar = $this->createMock(CalendarService::class);
		$calendar->expects(self::never())->method('hasPersonalCalendar');
		$plugin = new CalendarPlugin($calendar);

		self::assertSame([], $plugin->fetchAllForCalendarHome($principalUri));
		self::assertFalse($plugin->hasCalendarInCalendarHome($principalUri, CalendarPlugin::CALENDAR_URI));
	}

	public function testOnlyItsOwnUriIsFound(): void {
		$calendar = $this->createMock(CalendarService::class);
		$calendar->method('hasPersonalCalendar')->willReturn(true);
		$plugin = new CalendarPlugin($calendar);

		self::assertNull($plugin->getCalendarInCalendarHome('principals/users/alice', 'personal'));
		self::assertFalse($plugin->hasCalendarInCalendarHome('principals/users/alice', 'personal'));
		self::assertInstanceOf(PersonalCalendar::class, $plugin->getCalendarInCalendarHome('principals/users/alice', CalendarPlugin::CALENDAR_URI));
	}
}
