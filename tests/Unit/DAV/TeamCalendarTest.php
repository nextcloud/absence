<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\DAV;

use OCA\Absence\DAV\TeamCalendar;
use OCA\Absence\Service\CalendarService;
use PHPUnit\Framework\TestCase;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\DAV\Exception\Forbidden;

class TeamCalendarTest extends TestCase {
	private const PRINCIPAL = 'principals/users/alice';
	private const TRANSP = '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp';
	private const CTAG = '{http://calendarserver.org/ns/}getctag';

	/**
	 * @param array<string, array{ics: string, lastModified: int|null}> $events
	 */
	private function calendar(array $events = []): TeamCalendar {
		$service = $this->createMock(CalendarService::class);
		$service->method('teamEvents')->with('alice')->willReturn($events);
		return new TeamCalendar(self::PRINCIPAL, 'alice', $service);
	}

	private static function event(string $summary): array {
		return ['ics' => "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:absence-team-1\r\nSUMMARY:" . $summary . "\r\nDTSTART;VALUE=DATE:20260706\r\nTRANSP:TRANSPARENT\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", 'lastModified' => 1780000000];
	}

	public function testOnlyItsOwnerReadsItNotTheirDelegates(): void {
		$calendar = $this->calendar(['absence-team-1.ics' => self::event('Bob · Absent')]);
		$event = $calendar->getChild('absence-team-1.ics');

		self::assertSame([self::PRINCIPAL], array_values(array_unique(array_column($calendar->getACL(), 'principal'))));
		self::assertSame([['privilege' => '{DAV:}read', 'principal' => self::PRINCIPAL, 'protected' => true]], $event->getACL());
		self::assertSame(self::PRINCIPAL, $calendar->getOwner());
	}

	public function testNobodyCanWriteToIt(): void {
		$calendar = $this->calendar(['absence-team-1.ics' => self::event('Bob · Absent')]);
		$event = $calendar->getChild('absence-team-1.ics');

		foreach ([
			static fn () => $calendar->createFile('mine.ics', 'BEGIN:VCALENDAR'),
			static fn () => $calendar->delete(),
			static fn () => $event->put('BEGIN:VCALENDAR'),
			static fn () => $event->delete(),
		] as $write) {
			try {
				$write();
				self::fail('A write to the team calendar went through');
			} catch (Forbidden) {
				self::addToAssertionCount(1);
			}
		}
	}

	public function testItNeverMakesItsOwnerBusy(): void {
		$transp = $this->calendar()->getProperties([self::TRANSP])[self::TRANSP];

		self::assertInstanceOf(ScheduleCalendarTransp::class, $transp);
		self::assertSame(ScheduleCalendarTransp::TRANSPARENT, $transp->getValue());
	}

	public function testItsCtagChangesWithItsEventsOnly(): void {
		$ctag = $this->calendar(['absence-team-1.ics' => self::event('Bob · Absent')])->getProperties([self::CTAG])[self::CTAG];

		self::assertSame($ctag, $this->calendar(['absence-team-1.ics' => self::event('Bob · Absent')])->getProperties([self::CTAG])[self::CTAG]);
		self::assertNotSame($ctag, $this->calendar(['absence-team-1.ics' => self::event('Bob · Annual leave')])->getProperties([self::CTAG])[self::CTAG]);
		self::assertNotSame($ctag, $this->calendar()->getProperties([self::CTAG])[self::CTAG]);
	}
}
