<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\DAV\CalendarPlugin;
use OCA\Absence\DAV\LeaveEvent;
use OCA\Absence\DAV\PersonalCalendar;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\ConfigService;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\Forbidden;
use Sabre\VObject\Reader;

/**
 * The personal Absence calendar is built from the leave in force and nobody
 * can write to it (§10).
 */
class PersonalCalendarTest extends TestCase {
	private const EMPLOYEE = 'itest-personalcal-emp';
	private const PRINCIPAL = 'principals/users/' . self::EMPLOYEE;

	private LeaveRequestMapper $mapper;
	private IAppConfig $appConfig;
	/** @var LeaveRequest[] */
	private array $requests = [];
	/** @var array<string, bool> */
	private array $calDav = [];

	protected function setUp(): void {
		parent::setUp();
		Server::get(IUserManager::class)->createUser(self::EMPLOYEE, 'Itest-Password-personalcal-1');
		$this->mapper = Server::get(LeaveRequestMapper::class);
		$this->appConfig = Server::get(IAppConfig::class);
		foreach ([ConfigLexicon::KEY_CALDAV_PERSONAL, ConfigLexicon::KEY_CALDAV_SHARED] as $key) {
			$this->calDav[$key] = $this->appConfig->getValueBool(ConfigService::APP_ID, $key, true);
		}
		$this->appConfig->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_PERSONAL, true);
	}

	protected function tearDown(): void {
		foreach ($this->requests as $request) {
			$this->mapper->delete($request);
		}
		foreach ($this->calDav as $key => $value) {
			$this->appConfig->setValueBool(ConfigService::APP_ID, $key, $value);
		}
		Server::get(IUserManager::class)->get(self::EMPLOYEE)?->delete();
		parent::tearDown();
	}

	public function testItHoldsTheLeaveInForce(): void {
		$approved = $this->leave('2026-07-06', '2026-07-10', LeaveRequest::STATUS_APPROVED);
		$withdrawing = $this->leave('2026-08-03', '2026-08-04', LeaveRequest::STATUS_WITHDRAWAL_PENDING);
		$this->leave('2026-09-01', '2026-09-01', LeaveRequest::STATUS_PENDING);
		$this->leave('2026-09-02', '2026-09-02', LeaveRequest::STATUS_CANCELLED);
		$this->leave('2026-09-03', '2026-09-03', LeaveRequest::STATUS_REJECTED);

		$calendar = $this->calendar();

		self::assertSame('app-generated--absence--absence', $calendar->getName());
		self::assertSame(
			['absence-' . $approved->getId() . '.ics', 'absence-' . $withdrawing->getId() . '.ics'],
			array_map(static fn (LeaveEvent $event): string => $event->getName(), $calendar->getChildren()),
		);
		$event = Reader::read($calendar->getChild('absence-' . $approved->getId() . '.ics')->get())->VEVENT;
		self::assertSame('20260706', (string)$event->DTSTART);
		self::assertSame('20260711', (string)$event->DTEND);
		self::assertSame('OPAQUE', (string)$event->TRANSP);
	}

	public function testThereIsNoCalendarWithoutLeaveInForceOrWhileSwitchedOff(): void {
		$plugin = Server::get(CalendarPlugin::class);
		$this->leave('2026-09-01', '2026-09-01', LeaveRequest::STATUS_PENDING);
		self::assertSame([], $plugin->fetchAllForCalendarHome(self::PRINCIPAL));

		$this->leave('2026-07-06', '2026-07-10', LeaveRequest::STATUS_APPROVED);
		self::assertCount(1, $plugin->fetchAllForCalendarHome(self::PRINCIPAL));
		self::assertNull($plugin->getCalendarInCalendarHome(self::PRINCIPAL, 'personal'));

		$this->appConfig->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_PERSONAL, false);
		self::assertSame([], $plugin->fetchAllForCalendarHome(self::PRINCIPAL));
		self::assertFalse($plugin->hasCalendarInCalendarHome(self::PRINCIPAL, CalendarPlugin::CALENDAR_URI));
	}

	public function testNobodyCanWriteToIt(): void {
		$approved = $this->leave('2026-07-06', '2026-07-10', LeaveRequest::STATUS_APPROVED);
		$calendar = $this->calendar();
		$event = $calendar->getChild('absence-' . $approved->getId() . '.ics');

		$privileges = array_unique(array_column(array_merge($calendar->getACL(), $event->getACL()), 'privilege'));
		sort($privileges);
		self::assertSame(['{DAV:}read', '{DAV:}write-properties'], $privileges);
		self::assertSame(['{DAV:}read'], array_values(array_unique(array_column($event->getACL(), 'privilege'))));

		foreach ([
			static fn () => $calendar->createFile('mine.ics', 'BEGIN:VCALENDAR'),
			static fn () => $calendar->delete(),
			static fn () => $event->put('BEGIN:VCALENDAR'),
			static fn () => $event->delete(),
			static fn () => $event->setName('moved.ics'),
		] as $write) {
			try {
				$write();
				self::fail('A write to the Absence calendar went through');
			} catch (Forbidden) {
				self::addToAssertionCount(1);
			}
		}
	}

	public function testATimeRangeQueryFindsTheLeaveInIt(): void {
		$this->leave('2026-07-06', '2026-07-10', LeaveRequest::STATUS_APPROVED);
		$august = $this->leave('2026-08-03', '2026-08-04', LeaveRequest::STATUS_APPROVED);

		$names = $this->calendar()->calendarQuery([
			'name' => 'VCALENDAR',
			'comp-filters' => [[
				'name' => 'VEVENT',
				'comp-filters' => [],
				'prop-filters' => [],
				'is-not-defined' => false,
				'time-range' => ['start' => new \DateTime('2026-08-01'), 'end' => new \DateTime('2026-09-01')],
			]],
			'prop-filters' => [],
			'is-not-defined' => false,
			'time-range' => null,
		]);

		self::assertSame(['absence-' . $august->getId() . '.ics'], $names);
	}

	public function testAnEventOnlyChangesWithItsLeave(): void {
		$approved = $this->leave('2026-07-06', '2026-07-10', LeaveRequest::STATUS_APPROVED);
		$name = 'absence-' . $approved->getId() . '.ics';
		$etag = $this->calendar()->getChild($name)->getETag();

		self::assertSame($etag, $this->calendar()->getChild($name)->getETag());

		$approved->setEndDate('2026-07-09');
		$approved->setUpdatedAt(new \DateTime('2026-06-02 08:00:00'));
		$this->mapper->update($approved);
		self::assertNotSame($etag, $this->calendar()->getChild($name)->getETag());
	}

	public function testAConfidentialTypeIsNotNamed(): void {
		$confidential = array_values(array_filter(
			Server::get(LeaveTypeMapper::class)->findAll(),
			static fn (LeaveType $type): bool => $type->getHrOnly(),
		));
		if ($confidential === []) {
			self::markTestSkipped('No confidential leave type seeded');
		}
		$leave = $this->leave('2026-07-06', '2026-07-10', LeaveRequest::STATUS_APPROVED, $confidential[0]->getId());

		$summary = (string)Reader::read($this->calendar()->getChild('absence-' . $leave->getId() . '.ics')->get())->VEVENT->SUMMARY;

		self::assertSame('Absence', $summary);
	}

	private function calendar(): PersonalCalendar {
		$calendar = Server::get(CalendarPlugin::class)->getCalendarInCalendarHome(self::PRINCIPAL, CalendarPlugin::CALENDAR_URI);
		self::assertInstanceOf(PersonalCalendar::class, $calendar);
		return $calendar;
	}

	private function leave(string $start, string $end, string $status, int $typeId = 1): LeaveRequest {
		$request = new LeaveRequest();
		$request->setEmployeeUid(self::EMPLOYEE);
		$request->setTypeId($typeId);
		$request->setStartDate($start);
		$request->setEndDate($end);
		$request->setWorkingDays(1.0);
		$request->setStatus($status);
		$request->setCreatedAt(new \DateTime('2026-06-01 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2026-06-01 09:00:00'));
		return $this->requests[] = $this->mapper->insert($request);
	}
}
