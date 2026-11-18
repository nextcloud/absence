<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Migration\Version1019Date20261118120000;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Tests\Integration\Fixtures\RecordingActivities;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\Calendar;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The upgrade deletes the team calendar earlier versions stored and shared
 * (§10) for good, and tells nobody it was shared with.
 */
class StoredTeamCalendarRemovalTest extends TestCase {
	private const PRINCIPAL = 'principals/system/absence';
	private const GROUP = 'itest-teamcal-staff';
	private const MEMBER = 'itest-teamcal-member';
	private const SYNC_JOB = 'OCA\Absence\BackgroundJob\CalendarSyncJob';
	private const SYNC_CURSOR = 'calendar_sync_cursor';

	private CalDavBackend $backend;
	private LeaveRequestMapper $mapper;
	private LeaveRequest $request;
	private int $calendarId;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$member = Server::get(IUserManager::class)->createUser(self::MEMBER, 'Itest-Password-teamcal-1');
		Server::get(IGroupManager::class)->createGroup(self::GROUP)->addUser($member);
	}

	public static function tearDownAfterClass(): void {
		Server::get(IUserManager::class)->get(self::MEMBER)?->delete();
		Server::get(IGroupManager::class)->get(self::GROUP)?->delete();
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->backend = Server::get(CalDavBackend::class);
		$this->mapper = Server::get(LeaveRequestMapper::class);

		$this->calendarId = (int)$this->backend->createCalendar(self::PRINCIPAL, 'absence-team', ['{DAV:}displayname' => 'Team absences']);
		foreach (['absence-1-x.ics', 'absence-2-x.ics'] as $i => $objectUri) {
			$this->backend->createCalendarObject($this->calendarId, $objectUri, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:" . $objectUri . "\r\nSUMMARY:Robin · Absent\r\nDTSTART;VALUE=DATE:2026070" . ($i + 6) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
		}
		$this->backend->deleteCalendarObject($this->calendarId, 'absence-2-x.ics');
		$calendar = new Calendar($this->backend, $this->backend->getCalendarById($this->calendarId), Server::get(IFactory::class)->get('dav'), Server::get(IConfig::class), Server::get(LoggerInterface::class));
		$this->backend->updateShares($calendar, [['href' => 'principal:principals/groups/' . self::GROUP, 'readOnly' => true]], []);

		$request = new LeaveRequest();
		$request->setEmployeeUid(self::MEMBER);
		$request->setTypeId(1);
		$request->setStartDate('2026-07-06');
		$request->setEndDate('2026-07-06');
		$request->setWorkingDays(1.0);
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		$request->setCalendarEventUri($this->calendarId . ':absence-1-x.ics');
		$request->setCreatedAt(new \DateTime('2026-06-01 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2026-06-01 09:00:00'));
		$this->request = $this->mapper->insert($request);

		Server::get(IJobList::class)->add(self::SYNC_JOB);
		Server::get(IAppConfig::class)->setValueInt(ConfigService::APP_ID, self::SYNC_CURSOR, $this->request->getId());
	}

	protected function tearDown(): void {
		$this->mapper->delete($this->request);
		if ($this->backend->getCalendarById($this->calendarId) !== null) {
			$this->backend->deleteCalendar($this->calendarId, true);
		}
		parent::tearDown();
	}

	public function testTheStoredCalendarIsGoneForGoodAndNobodyIsTold(): void {
		RecordingActivities::register();

		$this->migrate(Server::get(ContainerInterface::class));

		self::assertSame([], RecordingActivities::$published);
		self::assertNull($this->backend->getCalendarById($this->calendarId));
		self::assertSame(0, $this->events());
		self::assertSame([], $this->backend->getShares($this->calendarId));
		$this->assertTheRestIsCleared();
	}

	public function testItRunsWhenTheCalendarIsGoneAlready(): void {
		$this->backend->deleteCalendar($this->calendarId, true);

		$this->migrate(Server::get(ContainerInterface::class));

		$this->assertTheRestIsCleared();
	}

	public function testWithoutTheDavAppTheRestIsStillCleared(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no dav'));

		$this->migrate($container);

		self::assertNotNull($this->backend->getCalendarById($this->calendarId));
		$this->assertTheRestIsCleared();
	}

	private function migrate(ContainerInterface $container): void {
		$migration = new Version1019Date20261118120000(
			Server::get(IDBConnection::class),
			$container,
			Server::get(IJobList::class),
			Server::get(IAppConfig::class),
			Server::get(LoggerInterface::class),
		);
		$migration->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);
	}

	private function assertTheRestIsCleared(): void {
		self::assertNull($this->mapper->find($this->request->getId())->getCalendarEventUri());
		self::assertFalse(Server::get(IJobList::class)->has(self::SYNC_JOB, null));
		self::assertFalse(Server::get(IAppConfig::class)->hasKey(ConfigService::APP_ID, self::SYNC_CURSOR));
	}

	/** The calendar's events, those in the trash bin included */
	private function events(): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('calendarobjects')
			->where($qb->expr()->eq('calendarid', $qb->createNamedParameter($this->calendarId)))
			->andWhere($qb->expr()->eq('calendartype', $qb->createNamedParameter(CalDavBackend::CALENDAR_TYPE_CALENDAR)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}
}
