<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Migration\Version1024Date20261126150000;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The upgrade removes the personal calendars 1.0.12 stored in employees'
 * calendar homes (§10), and only what 1.0.12 wrote there.
 */
class StoredPersonalCalendarRemovalTest extends TestCase {
	private const USER = 'itest-personalcal-robin';
	private const PRINCIPAL = 'principals/users/' . self::USER;

	private CalDavBackend $backend;
	/** @var list<int> */
	private array $calendars = [];

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		Server::get(IUserManager::class)->createUser(self::USER, 'Itest-Password-personalcal-1');
	}

	public static function tearDownAfterClass(): void {
		Server::get(IUserManager::class)->get(self::USER)?->delete();
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->backend = Server::get(CalDavBackend::class);
	}

	protected function tearDown(): void {
		foreach ($this->calendars as $id) {
			if ($this->backend->getCalendarById($id) !== null) {
				$this->backend->deleteCalendar($id, true);
			}
		}
		parent::tearDown();
	}

	public function testTheCalendarOf1012IsGoneForGood(): void {
		$id = $this->calendar('absence', 'Absence', ['absence-12-0a1b2c3d.ics', 'absence-13-0a1b2c3d.ics']);
		$this->backend->deleteCalendarObject($id, 'absence-13-0a1b2c3d.ics');

		$this->migrate(Server::get(ContainerInterface::class));

		self::assertNull($this->backend->getCalendarById($id));
		self::assertSame([], $this->events($id));
	}

	public function testEventsOfTheirOwnStayWithTheCalendar(): void {
		$id = $this->calendar('absence', 'Absence', ['absence-12-0a1b2c3d.ics', 'dentist.ics']);
		$this->backend->deleteCalendarObject($id, 'absence-12-0a1b2c3d.ics');
		$this->addEvent($id, 'absence-14-0a1b2c3d.ics');

		$this->migrate(Server::get(ContainerInterface::class));

		self::assertNotNull($this->backend->getCalendarById($id));
		self::assertSame(['dentist.ics'], $this->events($id));
	}

	public function testAnEmptyCalendarGoesOnlyUnderTheNameOf1012(): void {
		$stored = $this->calendar('absence', 'Absence', []);
		$renamed = $this->calendar('absence', 'Holidays', [], self::PRINCIPAL . '-other');

		$this->migrate(Server::get(ContainerInterface::class));

		self::assertNull($this->backend->getCalendarById($stored));
		self::assertNotNull($this->backend->getCalendarById($renamed));
	}

	public function testOtherCalendarsAreLeftAlone(): void {
		$personal = $this->calendar('personal', 'Personal', ['absence-12-0a1b2c3d.ics']);
		$system = $this->calendar('absence', 'Absence', ['absence-12-0a1b2c3d.ics'], 'principals/system/itest-personalcal');

		$this->migrate(Server::get(ContainerInterface::class));

		self::assertSame(['absence-12-0a1b2c3d.ics'], $this->events($personal));
		self::assertSame(['absence-12-0a1b2c3d.ics'], $this->events($system));
	}

	public function testWithoutTheDavAppNothingChanges(): void {
		$id = $this->calendar('absence', 'Absence', ['absence-12-0a1b2c3d.ics']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no dav'));

		$this->migrate($container);

		self::assertSame(['absence-12-0a1b2c3d.ics'], $this->events($id));
	}

	/**
	 * @param list<string> $events
	 */
	private function calendar(string $uri, string $displayName, array $events, string $principal = self::PRINCIPAL): int {
		$id = (int)$this->backend->createCalendar($principal, $uri, ['{DAV:}displayname' => $displayName]);
		$this->calendars[] = $id;
		foreach ($events as $event) {
			$this->addEvent($id, $event);
		}
		return $id;
	}

	private function addEvent(int $calendarId, string $objectUri): void {
		$this->backend->createCalendarObject($calendarId, $objectUri, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:" . $objectUri . "\r\nSUMMARY:Vacation\r\nDTSTART;VALUE=DATE:20260706\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
	}

	private function migrate(ContainerInterface $container): void {
		$migration = new Version1024Date20261126150000(
			Server::get(IDBConnection::class),
			$container,
			Server::get(LoggerInterface::class),
		);
		$migration->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);
	}

	/**
	 * The calendar's event uris, those in the trash bin included
	 *
	 * @return list<string>
	 */
	private function events(int $calendarId): array {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('uri')
			->from('calendarobjects')
			->where($qb->expr()->eq('calendarid', $qb->createNamedParameter($calendarId)))
			->andWhere($qb->expr()->eq('calendartype', $qb->createNamedParameter(CalDavBackend::CALENDAR_TYPE_CALENDAR)))
			->orderBy('uri');
		$result = $qb->executeQuery();
		$uris = [];
		while (($uri = $result->fetchOne()) !== false) {
			$uris[] = (string)$uri;
		}
		$result->closeCursor();
		return $uris;
	}
}
