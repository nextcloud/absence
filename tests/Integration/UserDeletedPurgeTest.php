<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\BackgroundJob\PurgeRetryJob;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\PurgeRetryMapper;
use OCA\Absence\Db\RequestEvent;
use OCA\Absence\Listener\UserDeletedListener;
use OCA\Absence\Service\AttachmentService;
use OCA\Absence\Service\EmployeeService;
use OCA\Absence\Service\NotificationService;
use OCA\Absence\Service\RequestService;
use OCA\Absence\Service\UserPurgeService;
use OCA\Absence\Tests\Integration\Fixtures\RecordingNotifications;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use OCP\Server;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What is left of someone once their account is deleted (§17), against a real server.
 */
class UserDeletedPurgeTest extends TestCase {
	private const GONE = 'itest-purge-gone';
	private const OTHER = 'itest-purge-other';
	private const REGION = 'ZZ-ITEST-PURGE';
	/** Marks this test's row of the holiday history: Oracle cannot filter on its text column */
	private const HISTORY_ACTION = 'itest_purge';
	/** No entitlement has this id: the history row stands alone */
	private const ENTITLEMENT = 990017;
	/** Named by a row, never an account */
	private const ORPHAN = 'itest-purge-orphan';
	private static bool $registered = false;

	private IDBConnection $db;
	private LeaveRequest $own;
	private LeaveRequest $decided;

	protected function setUp(): void {
		parent::setUp();
		if (!self::$registered) {
			Server::get(IManager::class)->registerApp(RecordingNotifications::class);
			self::$registered = true;
		}
		RecordingNotifications::$processed = [];
		$this->db = Server::get(IDBConnection::class);
		$users = Server::get(IUserManager::class);
		$users->createUser(self::GONE, 'Itest-Password-purge-1');
		$users->createUser(self::OTHER, 'Itest-Password-purge-2');

		$this->own = $this->request(self::GONE, null);
		$this->decided = $this->request(self::OTHER, self::GONE);
		$this->insert('absence_comments', ['request_id' => $this->own->getId(), 'author_uid' => self::OTHER, 'body' => 'itest', 'created_at' => '2026-03-01 09:00:00']);
		$this->insert('absence_holidays', ['region' => self::REGION, 'date' => '2026-05-01', 'label' => 'itest', 'created_by' => self::GONE, 'updated_by' => self::GONE]);
		$this->insert('absence_holiday_history', ['action' => self::HISTORY_ACTION, 'actor_uid' => self::GONE, 'detail' => '{"region":"' . self::REGION . '"}', 'created_at' => '2026-03-01 09:00:00']);
		$this->insert('absence_emp_history', ['employee_uid' => self::OTHER, 'actor_uid' => self::GONE, 'field' => 'contract', 'detail' => '{}', 'created_at' => '2026-03-01 09:00:00']);
		// What they did on the other employee's request and entitlement
		$this->insert('absence_comments', ['request_id' => $this->decided->getId(), 'author_uid' => self::GONE, 'body' => 'itest', 'created_at' => '2026-03-01 09:00:00']);
		$this->insert('absence_request_events', ['request_id' => $this->decided->getId(), 'actor_uid' => self::GONE, 'event_type' => 'request_approved', 'created_at' => '2026-03-01 09:00:00']);
		$this->insert('absence_request_events', ['request_id' => $this->decided->getId(), 'actor_uid' => RequestEvent::ACTOR_SYSTEM, 'event_type' => 'request_escalated', 'created_at' => '2026-03-01 09:00:00']);
		$this->insert('absence_ent_events', ['entitlement_id' => self::ENTITLEMENT, 'employee_uid' => self::OTHER, 'actor_uid' => self::GONE, 'field' => 'base_days', 'old_value' => 20, 'new_value' => 25, 'created_at' => '2026-03-01 09:00:00']);
		$this->insert('absence_attachments', ['request_id' => $this->decided->getId(), 'uploader_uid' => self::GONE, 'name' => 'itest.pdf', 'mime' => 'application/pdf', 'size' => 1, 'created_at' => '2026-03-01 09:00:00']);
	}

	protected function tearDown(): void {
		$users = Server::get(IUserManager::class);
		foreach ([self::GONE, self::OTHER] as $uid) {
			$users->get($uid)?->delete();
		}
		// What the listener keeps, and what a failed purge leaves behind
		$this->deleteWhere('absence_requests', 'id', [$this->own->getId(), $this->decided->getId()], IQueryBuilder::PARAM_INT_ARRAY);
		$this->deleteWhere('absence_comments', 'request_id', [$this->own->getId(), $this->decided->getId()], IQueryBuilder::PARAM_INT_ARRAY);
		$this->deleteWhere('absence_request_events', 'request_id', [$this->own->getId(), $this->decided->getId()], IQueryBuilder::PARAM_INT_ARRAY);
		$this->deleteWhere('absence_attachments', 'request_id', [$this->own->getId(), $this->decided->getId()], IQueryBuilder::PARAM_INT_ARRAY);
		$this->deleteWhere('absence_ent_events', 'entitlement_id', [self::ENTITLEMENT], IQueryBuilder::PARAM_INT_ARRAY);
		$this->deleteWhere('absence_holidays', 'region', [self::REGION], IQueryBuilder::PARAM_STR_ARRAY);
		$this->deleteWhere('absence_holiday_history', 'action', [self::HISTORY_ACTION], IQueryBuilder::PARAM_STR_ARRAY);
		$this->deleteWhere('absence_emp_history', 'employee_uid', [self::OTHER], IQueryBuilder::PARAM_STR_ARRAY);
		$this->deleteWhere('absence_purge_retry', 'uid', [self::GONE, self::ORPHAN], IQueryBuilder::PARAM_STR_ARRAY);
		parent::tearDown();
	}

	public function testTheDeletedUserIsNoLongerNamedOnWhatTheyDecidedOrEdited(): void {
		Server::get(IUserManager::class)->get(self::GONE)->delete();

		self::assertSame([null], $this->column('absence_requests', 'decided_by', 'id', $this->decided->getId()));
		self::assertSame([null], $this->column('absence_holidays', 'created_by', 'region', self::REGION));
		self::assertSame([null], $this->column('absence_holidays', 'updated_by', 'region', self::REGION));
		self::assertSame([null], $this->column('absence_holiday_history', 'actor_uid', 'action', self::HISTORY_ACTION));
		self::assertSame([null], $this->column('absence_emp_history', 'actor_uid', 'employee_uid', self::OTHER));
		self::assertSame([null], $this->column('absence_ent_events', 'actor_uid', 'entitlement_id', self::ENTITLEMENT));
		self::assertSame([null], $this->column('absence_attachments', 'uploader_uid', 'request_id', $this->decided->getId()));
		self::assertEqualsCanonicalizing([null, RequestEvent::ACTOR_SYSTEM], $this->column('absence_request_events', 'actor_uid', 'request_id', $this->decided->getId()));
	}

	public function testTheCommentNotificationsQuotingThemAreTakenBack(): void {
		Server::get(IUserManager::class)->get(self::GONE)->delete();

		$taken = array_map(
			static fn (INotification $n): array => [$n->getApp(), $n->getUser(), $n->getObjectType(), $n->getObjectId(), $n->getSubject()],
			RecordingNotifications::$processed,
		);
		self::assertContains(['absence', '', 'absence_request', (string)$this->decided->getId(), NotificationService::SUBJECT_COMMENT], $taken);
		self::assertSame([], $this->column('absence_comments', 'author_uid', 'request_id', $this->decided->getId()));
	}

	public function testPurgingAnAccountWithTheJobsUidKeepsTheJobsEntries(): void {
		Server::get(UserPurgeService::class)->purge(RequestEvent::ACTOR_SYSTEM);

		self::assertContains(RequestEvent::ACTOR_SYSTEM, $this->column('absence_request_events', 'actor_uid', 'request_id', $this->decided->getId()));
	}

	public function testTheHistoryOfOtherRequestsKeepsTheirDecisionsButNotTheirComments(): void {
		foreach (['comment_added' => 'itest comment', 'request_approved' => 'itest decision'] as $eventType => $text) {
			$this->insert('absence_request_events', ['request_id' => $this->decided->getId(), 'actor_uid' => self::GONE, 'event_type' => $eventType, 'hr_detail' => $text, 'created_at' => '2026-03-01 09:00:00']);
		}

		Server::get(IUserManager::class)->get(self::GONE)->delete();

		$details = $this->column('absence_request_events', 'hr_detail', 'request_id', $this->decided->getId());
		self::assertContains('itest decision', $details);
		self::assertNotContains('itest comment', $details);
	}

	public function testTheUidsNoAccountHasAreFoundButNotTheJobs(): void {
		$this->insert('absence_request_events', ['request_id' => $this->decided->getId(), 'actor_uid' => self::ORPHAN, 'event_type' => 'request_approved', 'created_at' => '2026-03-01 09:00:00']);

		$orphans = Server::get(UserPurgeService::class)->findOrphanUids();

		self::assertContains(self::ORPHAN, $orphans);
		self::assertNotContains(RequestEvent::ACTOR_SYSTEM, $orphans);
		self::assertNotContains(self::GONE, $orphans);
		self::assertNotContains(self::OTHER, $orphans);
	}

	public function testAFailedPurgeIsRetriedByTheJob(): void {
		$purge = $this->createMock(UserPurgeService::class);
		$purge->method('purge')->willThrowException(new \RuntimeException('itest'));
		$listener = new UserDeletedListener($purge, Server::get(PurgeRetryMapper::class), Server::get(LoggerInterface::class));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::GONE);
		Server::get(IUserManager::class)->get(self::GONE)->delete();
		$this->insert('absence_purge_retry', ['uid' => self::OTHER]);

		$listener->handle(new UserDeletedEvent($user));
		self::assertSame([self::GONE], $this->column('absence_purge_retry', 'uid', 'uid', self::GONE));

		$job = Server::get(PurgeRetryJob::class);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);

		self::assertSame([], $this->column('absence_purge_retry', 'uid', 'uid', self::GONE));
		// Somebody has that uid: dropped from the list, and their data kept
		self::assertSame([], $this->column('absence_purge_retry', 'uid', 'uid', self::OTHER));
		self::assertSame([self::OTHER], $this->column('absence_requests', 'employee_uid', 'id', $this->decided->getId()));
	}

	public function testNotificationsAboutTheirRequestsAreTakenBackFromEveryone(): void {
		Server::get(IUserManager::class)->get(self::GONE)->delete();

		$taken = array_map(
			static fn (INotification $n): array => [$n->getApp(), $n->getUser(), $n->getObjectType(), $n->getObjectId(), $n->getSubject()],
			RecordingNotifications::$processed,
		);
		self::assertContains(['absence', '', 'absence_request', (string)$this->own->getId(), ''], $taken);
		self::assertNotContains(['absence', '', 'absence_request', (string)$this->decided->getId(), ''], $taken);
	}

	public function testHrsNotificationsAboutTheirManagerChangesAreTakenBack(): void {
		Server::get(IUserManager::class)->get(self::GONE)->delete();

		$taken = array_map(
			static fn (INotification $n): array => [$n->getApp(), $n->getUser(), $n->getObjectType(), $n->getObjectId(), $n->getSubject()],
			RecordingNotifications::$processed,
		);
		self::assertContains(['absence', '', 'absence_manager', self::GONE, ''], $taken);
		self::assertNotContains(['absence', '', 'absence_manager', self::OTHER, ''], $taken);
	}

	public function testThePurgeGoesToTheAuditLogOnceItIsDone(): void {
		$messages = $this->auditMessages();

		Server::get(IUserManager::class)->get(self::GONE)->delete();

		self::assertContains('Absence data of ' . self::GONE . ' purged', $messages->getArrayCopy());
	}

	public function testAFailureLeavesNothingHalfRemoved(): void {
		$messages = $this->auditMessages();
		$employees = $this->createMock(EmployeeService::class);
		$employees->method('purge')->willThrowException(new \RuntimeException('itest'));
		$purge = new UserPurgeService(
			$this->db,
			Server::get(LeaveRequestMapper::class),
			Server::get(AttachmentService::class),
			Server::get(LoggerInterface::class),
			$employees,
			Server::get(RequestService::class),
			Server::get(NotificationService::class),
			Server::get(IEventDispatcher::class),
			Server::get(IUserManager::class),
		);

		try {
			$purge->purge(self::GONE);
			self::fail('The purge was expected to fail');
		} catch (\RuntimeException) {
		}

		self::assertSame([self::OTHER], $this->column('absence_comments', 'author_uid', 'request_id', $this->own->getId()));
		self::assertSame([self::GONE], $this->column('absence_requests', 'employee_uid', 'id', $this->own->getId()));
		self::assertNotContains('Absence data of ' . self::GONE . ' purged', $messages->getArrayCopy());
	}

	/**
	 * @return \ArrayObject<int, string> filled as audit events are dispatched
	 */
	private function auditMessages(): \ArrayObject {
		$messages = new \ArrayObject();
		Server::get(IEventDispatcher::class)->addListener(CriticalActionPerformedEvent::class, static function (CriticalActionPerformedEvent $event) use ($messages): void {
			$messages->append(vsprintf($event->getLogMessage(), $event->getParameters()));
		});
		return $messages;
	}

	private function request(string $employee, ?string $decidedBy): LeaveRequest {
		$request = new LeaveRequest();
		$request->setEmployeeUid($employee);
		$request->setTypeId(1);
		$request->setStartDate('2026-03-02');
		$request->setEndDate('2026-03-03');
		$request->setWorkingDays(2.0);
		$request->setStatus($decidedBy === null ? LeaveRequest::STATUS_PENDING : LeaveRequest::STATUS_APPROVED);
		$request->setDecidedBy($decidedBy);
		$request->setCreatedAt(new \DateTime('2026-03-01 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2026-03-01 09:00:00'));
		return Server::get(LeaveRequestMapper::class)->insert($request);
	}

	/**
	 * @param array<string, int|string> $values
	 */
	private function insert(string $table, array $values): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table)->values(array_map(static fn (int|string $value) => $qb->createNamedParameter($value), $values));
		$qb->executeStatement();
	}

	/**
	 * @return list<?string>
	 */
	private function column(string $table, string $column, string $where, int|string $value): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($column)->from($table)->where($qb->expr()->eq($where, $qb->createNamedParameter($value)));
		return $qb->executeQuery()->fetchFirstColumn();
	}

	private function deleteWhere(string $table, string $column, array $values, int $type): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($values, $type)));
		$qb->executeStatement();
	}
}
