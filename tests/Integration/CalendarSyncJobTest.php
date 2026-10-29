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
use OCA\Absence\Service\ConfigService;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

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
		$request->setStartDate('2026-03-02');
		$request->setEndDate('2026-03-06');
		$request->setWorkingDays(5.0);
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		$request->setCreatedAt(new \DateTime('2026-01-01 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2026-01-01 09:00:00'));
		$this->request = $this->mapper->insert($request);
		// Start right before it, whatever else the test database holds
		$this->appConfig->setValueInt(ConfigService::APP_ID, ConfigLexicon::KEY_CALENDAR_SYNC_CURSOR, $this->request->getId() - 1);
	}

	protected function tearDown(): void {
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

	private function personalCalendarOnly(): void {
		$this->appConfig->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_PERSONAL, true);
		$this->appConfig->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_SHARED, false);
	}

	private function runJob(): void {
		$job = Server::get(CalendarSyncJob::class);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
