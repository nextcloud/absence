<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\BackgroundJob\CalendarSyncJob;
use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\CalendarService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Tests\Integration\Fixtures\FailingOnceCalDavBackend;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The job writes the calendar events approved leave has none of (§10).
 */
class CalendarSyncJobTest extends TestCase {
	private const EMPLOYEE = 'itest-calsync-emp';

	private LeaveRequestMapper $mapper;
	private IAppConfig $appConfig;
	private ?LeaveRequest $request = null;
	/** @var array<string, bool> */
	private array $calDav = [];

	protected function setUp(): void {
		parent::setUp();
		Server::get(IUserManager::class)->createUser(self::EMPLOYEE, 'Itest-Password-calsync-1');
		$this->mapper = Server::get(LeaveRequestMapper::class);
		$this->appConfig = Server::get(IAppConfig::class);
		foreach ([ConfigLexicon::KEY_CALDAV_PERSONAL, ConfigLexicon::KEY_CALDAV_SHARED] as $key) {
			$this->calDav[$key] = $this->appConfig->getValueBool(ConfigService::APP_ID, $key, true);
		}

		// Approved without events, as the leave import books it
		$request = new LeaveRequest();
		$request->setEmployeeUid(self::EMPLOYEE);
		$request->setTypeId(1);
		$request->setStartDate($this->day('+7 days'));
		$request->setEndDate($this->day('+11 days'));
		$request->setWorkingDays(5.0);
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		$request->setCreatedAt(new \DateTime('2026-01-01 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2026-01-01 09:00:00'));
		$this->request = $this->mapper->insert($request);
		// Start right before it, whatever else the test database holds
		$this->appConfig->setValueInt(ConfigService::APP_ID, ConfigLexicon::KEY_CALENDAR_SYNC_CURSOR, $this->request->getId() - 1);
	}

	protected function tearDown(): void {
		// The team calendar outlives the test user
		foreach (array_filter(explode(';', (string)$this->mapper->find($this->request->getId())->getCalendarEventUri())) as $ref) {
			[$calendarId, $objectUri] = explode(':', $ref);
			Server::get(CalDavBackend::class)->deleteCalendarObject((int)$calendarId, $objectUri, CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
		}
		$this->mapper->delete($this->request);
		foreach ($this->calDav as $key => $value) {
			$this->appConfig->setValueBool(ConfigService::APP_ID, $key, $value);
		}
		$this->appConfig->deleteKey(ConfigService::APP_ID, ConfigLexicon::KEY_CALENDAR_SYNC_CURSOR);
		Server::get(IUserManager::class)->get(self::EMPLOYEE)?->delete();
		parent::tearDown();
	}

	public function testApprovedLeaveWithoutEventsGetsThemOnce(): void {
		$this->personalCalendarOnly();

		$this->runJob();
		$uri = $this->mapper->find($this->request->getId())->getCalendarEventUri();
		$this->runJob();

		self::assertNotNull($uri);
		self::assertSame($uri, $this->mapper->find($this->request->getId())->getCalendarEventUri());
		[$calendarId, $objectUri] = explode(':', $uri);
		self::assertNotNull(Server::get(CalDavBackend::class)->getCalendarObject((int)$calendarId, $objectUri));
		self::assertCount(1, Server::get(CalDavBackend::class)->getCalendarObjects((int)$calendarId));
	}

	public function testFailedTeamCalendarWriteIsRetriedWithoutSecondPersonalEvent(): void {
		$this->bothCalendars();
		$backend = new FailingOnceCalDavBackend(Server::get(CalDavBackend::class), failOnWrite: 2);

		$this->runJob($this->calendarServiceOn($backend));
		self::assertNull($this->mapper->find($this->request->getId())->getCalendarEventUri());

		$this->rewind();
		$this->runJob($this->calendarServiceOn($backend));

		$uri = $this->mapper->find($this->request->getId())->getCalendarEventUri();
		self::assertNotNull($uri);
		$refs = explode(';', $uri);
		self::assertCount(2, $refs);
		foreach ($refs as $ref) {
			[$calendarId, $objectUri] = explode(':', $ref);
			self::assertNotNull(Server::get(CalDavBackend::class)->getCalendarObject((int)$calendarId, $objectUri));
		}
		[$personalCalendarId] = explode(':', $refs[0]);
		self::assertCount(1, Server::get(CalDavBackend::class)->getCalendarObjects((int)$personalCalendarId));
	}

	public function testRequestCancelledDuringTheRunKeepsNoEvent(): void {
		$this->personalCalendarOnly();
		$backend = new FailingOnceCalDavBackend(Server::get(CalDavBackend::class), onWrite: function (): void {
			$request = $this->mapper->find($this->request->getId());
			$request->setStatus(LeaveRequest::STATUS_CANCELLED);
			$this->mapper->update($request);
		});

		$this->runJob($this->calendarServiceOn($backend));

		self::assertNull($this->mapper->find($this->request->getId())->getCalendarEventUri());
		$calendars = array_filter(
			Server::get(CalDavBackend::class)->getCalendarsForUser('principals/users/' . self::EMPLOYEE),
			static fn (array $calendar): bool => $calendar['uri'] === 'absence',
		);
		self::assertCount(1, $calendars);
		self::assertSame([], Server::get(CalDavBackend::class)->getCalendarObjects((int)reset($calendars)['id']));
	}

	private function bothCalendars(): void {
		$this->appConfig->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_PERSONAL, true);
		$this->appConfig->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_SHARED, true);
	}

	private function rewind(): void {
		$this->appConfig->setValueInt(ConfigService::APP_ID, ConfigLexicon::KEY_CALENDAR_SYNC_CURSOR, $this->request->getId() - 1);
	}

	/** The real service, on a calendar backend the test can interfere with */
	private function calendarServiceOn(FailingOnceCalDavBackend $backend): CalendarService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($backend);
		return new CalendarService(
			$container,
			Server::get(ConfigService::class),
			Server::get(LeaveTypeMapper::class),
			Server::get(IUserManager::class),
			Server::get(IFactory::class),
			Server::get(LoggerInterface::class),
		);
	}

	public function testLeaveThatHasEndedGetsNoEvents(): void {
		$this->personalCalendarOnly();
		$this->request->setStartDate($this->day('-5 days'));
		$this->request->setEndDate($this->day('-1 day'));
		$this->mapper->update($this->request);

		$this->runJob();

		self::assertNull($this->mapper->find($this->request->getId())->getCalendarEventUri());
	}

	public function testLeaveEndingTodayStillGetsEvents(): void {
		$this->personalCalendarOnly();
		$this->request->setStartDate($this->day('-4 days'));
		$this->request->setEndDate($this->day('today'));
		$this->mapper->update($this->request);

		$this->runJob();

		self::assertNotNull($this->mapper->find($this->request->getId())->getCalendarEventUri());
	}

	private function day(string $offset): string {
		return (new \DateTimeImmutable($offset, new \DateTimeZone('UTC')))->format('Y-m-d');
	}

	private function personalCalendarOnly(): void {
		$this->appConfig->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_PERSONAL, true);
		$this->appConfig->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_SHARED, false);
	}

	private function runJob(?CalendarService $calendar = null): void {
		$job = $calendar === null ? Server::get(CalendarSyncJob::class) : new CalendarSyncJob(
			Server::get(ITimeFactory::class),
			$this->mapper,
			$calendar,
			Server::get(ConfigService::class),
			$this->appConfig,
		);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
