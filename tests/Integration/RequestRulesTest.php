<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Db\RequestEvent;
use OCA\Absence\Db\RequestEventMapper;
use OCA\Absence\Exception\ConflictException;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\RequestService;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rules of a request against a real server: the note a leave type requires
 * (§5.1) and which requests HR can still edit (§5.5).
 */
class RequestRulesTest extends TestCase {
	private const HR = 'itest-rules-hr';
	private const EMPLOYEE = 'itest-rules-emp';
	private const HR_GROUP = 'itest-rules-hr';
	private const TYPE_KEY = 'itest_rules';

	/** @var array<string,mixed> */
	private array $snapshot = [];
	private RequestService $service;
	private LeaveRequestMapper $mapper;
	private LeaveType $noteType;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$users = Server::get(IUserManager::class);
		Server::get(IGroupManager::class)->createGroup(self::HR_GROUP)->addUser($users->createUser(self::HR, 'Itest-Password-rules-1'));
		$users->createUser(self::EMPLOYEE, 'Itest-Password-rules-2');
	}

	public static function tearDownAfterClass(): void {
		$users = Server::get(IUserManager::class);
		foreach ([self::HR, self::EMPLOYEE] as $uid) {
			$users->get($uid)?->delete();
		}
		Server::get(IGroupManager::class)->get(self::HR_GROUP)?->delete();
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(RequestService::class);
		$this->mapper = Server::get(LeaveRequestMapper::class);
		$this->snapshot = Server::get(ConfigService::class)->getAdminConfig();
		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, self::HR_GROUP);
		$appConfig->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_PERSONAL, false);
		$appConfig->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_SHARED, true);

		$type = new LeaveType();
		$type->setKey(self::TYPE_KEY);
		$type->setLabel('Itest leave with a note');
		$type->setCountsAgainstBalance(false);
		$type->setRequiresNote(true);
		$this->noteType = Server::get(LeaveTypeMapper::class)->insert($type);
	}

	protected function tearDown(): void {
		$db = Server::get(IDBConnection::class);
		$requests = $this->mapper->findFiltered(['employeeUid' => self::EMPLOYEE]);
		$requestIds = array_map(static fn (LeaveRequest $request): int => $request->getId(), $requests);
		foreach (['absence_comments', 'absence_request_events'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in('request_id', $qb->createNamedParameter($requestIds, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		}
		foreach ($requests as $request) {
			// The team calendar outlives the test users
			foreach (array_filter(explode(';', (string)$request->getCalendarEventUri())) as $ref) {
				[$calendarId, $objectUri] = explode(':', $ref);
				Server::get(CalDavBackend::class)->deleteCalendarObject((int)$calendarId, $objectUri, CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
			}
			$this->mapper->delete($request);
		}
		Server::get(LeaveTypeMapper::class)->delete($this->noteType);

		$appConfig = Server::get(IAppConfig::class);
		foreach ($this->snapshot as $key => $value) {
			$appConfig->deleteKey(ConfigService::APP_ID, $key);
			match (true) {
				is_bool($value) => $appConfig->setValueBool(ConfigService::APP_ID, $key, $value),
				is_int($value) => $appConfig->setValueInt(ConfigService::APP_ID, $key, $value),
				is_float($value) => $appConfig->setValueFloat(ConfigService::APP_ID, $key, $value),
				default => $appConfig->setValueString(ConfigService::APP_ID, $key, (string)$value),
			};
		}
		parent::tearDown();
	}

	public function testAnAttachmentNoteDoesNotStandInForTheRequiredReason(): void {
		try {
			$this->service->create(self::EMPLOYEE, $this->week(['reason' => '', 'attachmentNote' => 'x']));
			self::fail('Created without a reason');
		} catch (ValidationException) {
		}
		self::assertSame([], $this->mapper->findFiltered(['employeeUid' => self::EMPLOYEE]));

		$request = $this->service->create(self::EMPLOYEE, $this->week(['reason' => 'Moving house']));
		try {
			$this->service->update(self::EMPLOYEE, $request->getId(), ['reason' => ' ', 'attachmentNote' => 'x']);
			self::fail('Reason cleared by its owner');
		} catch (ValidationException) {
		}
		self::assertSame('Moving house', $this->mapper->find($request->getId())->getReason());
	}

	public function testHrNeedsTheRequiredReasonToo(): void {
		try {
			$this->service->create(self::HR, $this->week(['employeeUid' => self::EMPLOYEE, 'reason' => '']));
			self::fail('Recorded without a reason');
		} catch (ValidationException) {
		}

		$request = $this->service->create(self::HR, $this->week(['employeeUid' => self::EMPLOYEE, 'reason' => 'Moving house']));
		try {
			$this->service->update(self::HR, $request->getId(), ['reason' => '']);
			self::fail('Reason cleared by HR');
		} catch (ValidationException) {
		}
		self::assertSame('Moving house', $this->mapper->find($request->getId())->getReason());

		$this->service->update(self::HR, $request->getId(), ['reason' => 'Moving to Berlin']);
		self::assertSame('Moving to Berlin', $this->mapper->find($request->getId())->getReason());
	}

	public function testAnEmployeeKeepsALeaveTypeDisabledSinceTheyAsked(): void {
		$request = $this->service->create(self::EMPLOYEE, $this->week(['reason' => 'Moving house']));
		$this->noteType->setEnabled(false);
		Server::get(LeaveTypeMapper::class)->update($this->noteType);

		// Without the type, as the dialog sends an edit, and with it unchanged
		$this->service->update(self::EMPLOYEE, $request->getId(), ['startDate' => '2027-06-08', 'workingDays' => 4]);
		$this->service->update(self::EMPLOYEE, $request->getId(), ['typeId' => $this->noteType->getId(), 'endDate' => '2027-06-10', 'workingDays' => 3]);
		$stored = $this->mapper->find($request->getId());
		self::assertSame(
			[$this->noteType->getId(), '2027-06-08', '2027-06-10', 3.0],
			[$stored->getTypeId(), $stored->getStartDate(), $stored->getEndDate(), $stored->getWorkingDays()],
		);

		// Approved leave too: the edit waits for a decision in the same type
		$approved = $this->service->approve(self::HR, $request->getId(), null, $stored->getUpdatedAt()->format(\DateTimeInterface::ATOM));
		$edit = $this->service->update(self::EMPLOYEE, $approved->getId(), ['endDate' => '2027-06-09', 'workingDays' => 2]);
		self::assertSame([$this->noteType->getId(), $approved->getId()], [$edit->getTypeId(), $edit->getSupersedesId()]);
	}

	public function testAnEmployeeCannotSwitchToADisabledLeaveType(): void {
		$request = $this->service->create(self::EMPLOYEE, $this->week(['reason' => 'Moving house']));
		$type = new LeaveType();
		$type->setKey(self::TYPE_KEY . '_off');
		$type->setLabel('Itest disabled leave');
		$type->setCountsAgainstBalance(false);
		$type->setEnabled(false);
		$disabled = Server::get(LeaveTypeMapper::class)->insert($type);
		try {
			$this->service->update(self::EMPLOYEE, $request->getId(), ['typeId' => $disabled->getId()]);
			self::fail('Switched to a disabled type');
		} catch (ValidationException $e) {
			self::assertSame('This leave type is disabled.', $e->getMessage());
		} finally {
			Server::get(LeaveTypeMapper::class)->delete($disabled);
		}
		self::assertSame($this->noteType->getId(), $this->mapper->find($request->getId())->getTypeId());
	}

	#[DataProvider('closedStatuses')]
	public function testHrCannotEditAClosedRequest(string $status): void {
		$request = $this->seed($status);

		try {
			$this->service->update(self::HR, $request->getId(), [
				'typeId' => 1,
				'startDate' => '2027-06-14',
				'endDate' => '2027-06-25',
				'workingDays' => 10,
				'reason' => 'Changed afterwards',
			]);
			self::fail('Edited a request that is ' . $status);
		} catch (ConflictException) {
		}

		$stored = $this->mapper->find($request->getId());
		self::assertSame(
			[$status, $this->noteType->getId(), '2027-06-07', '2027-06-11', 5.0, 'Moving house'],
			[$stored->getStatus(), $stored->getTypeId(), $stored->getStartDate(), $stored->getEndDate(), $stored->getWorkingDays(), $stored->getReason()],
		);
		self::assertEquals($request->getUpdatedAt(), $stored->getUpdatedAt());
		self::assertSame([], Server::get(RequestEventMapper::class)->findForRequest($request->getId()));
	}

	/** @return array<string, array{string}> */
	public static function closedStatuses(): array {
		return [
			'cancelled' => [LeaveRequest::STATUS_CANCELLED],
			'rejected' => [LeaveRequest::STATUS_REJECTED],
		];
	}

	public function testHrEditingApprovedLeaveMovesItsCalendarEvent(): void {
		$request = $this->service->create(self::HR, $this->week(['employeeUid' => self::EMPLOYEE, 'reason' => 'Moving house']));
		self::assertStringContainsString('DTSTART;VALUE=DATE:20270607', $this->calendarEvent($request->getId()));

		$edited = $this->service->update(self::HR, $request->getId(), [
			'startDate' => '2027-06-14',
			'endDate' => '2027-06-16',
			'workingDays' => 3,
		]);

		self::assertSame(LeaveRequest::STATUS_APPROVED, $edited->getStatus());
		$stored = $this->mapper->find($request->getId());
		self::assertSame(
			[LeaveRequest::STATUS_APPROVED, '2027-06-14', '2027-06-16', 3.0, 'Moving house'],
			[$stored->getStatus(), $stored->getStartDate(), $stored->getEndDate(), $stored->getWorkingDays(), $stored->getReason()],
		);
		$event = $this->calendarEvent($request->getId());
		self::assertStringContainsString('DTSTART;VALUE=DATE:20270614', $event);
		self::assertStringContainsString('DTEND;VALUE=DATE:20270617', $event);
		$history = array_map(
			static fn (RequestEvent $entry): array => [$entry->getEventType(), $entry->getActorUid(), $entry->getDetail()],
			Server::get(RequestEventMapper::class)->findForRequest($request->getId()),
		);
		self::assertSame(
			['request_hr_edited', self::HR, 'Dates 2027-06-07 – 2027-06-11 → 2027-06-14 – 2027-06-16; Working days 5 days → 3 days (−2 days)'],
			end($history),
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function week(array $data): array {
		return $data + [
			'typeId' => $this->noteType->getId(),
			'startDate' => '2027-06-07',
			'endDate' => '2027-06-11',
			'workingDays' => 5,
		];
	}

	private function seed(string $status): LeaveRequest {
		$request = new LeaveRequest();
		$request->setEmployeeUid(self::EMPLOYEE);
		$request->setTypeId($this->noteType->getId());
		$request->setStatus($status);
		$request->setStartDate('2027-06-07');
		$request->setEndDate('2027-06-11');
		$request->setWorkingDays(5.0);
		$request->setReason('Moving house');
		$request->setCreatedAt(new \DateTime('2027-01-04 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2027-01-04 09:00:00'));
		return $this->mapper->insert($request);
	}

	/** The team calendar event of a request, as stored */
	private function calendarEvent(int $requestId): string {
		[$calendarId, $objectUri] = explode(':', (string)$this->mapper->find($requestId)->getCalendarEventUri());
		return (string)Server::get(CalDavBackend::class)->getCalendarObject((int)$calendarId, $objectUri)['calendardata'];
	}
}
