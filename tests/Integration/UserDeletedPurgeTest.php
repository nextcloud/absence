<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Listener\UserDeletedListener;
use OCA\Absence\Service\AttachmentService;
use OCA\Absence\Service\CalendarService;
use OCA\Absence\Service\EmployeeService;
use OCA\Absence\Service\NotificationService;
use OCA\Absence\Service\RequestService;
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
		$this->insert('absence_holiday_history', ['action' => 'added', 'actor_uid' => self::GONE, 'detail' => '{"region":"' . self::REGION . '"}', 'created_at' => '2026-03-01 09:00:00']);
		$this->insert('absence_employee_history', ['employee_uid' => self::OTHER, 'actor_uid' => self::GONE, 'field' => 'contract', 'detail' => '{}', 'created_at' => '2026-03-01 09:00:00']);
	}

	protected function tearDown(): void {
		$users = Server::get(IUserManager::class);
		foreach ([self::GONE, self::OTHER] as $uid) {
			$users->get($uid)?->delete();
		}
		// What the listener keeps, and what a failed purge leaves behind
		$this->deleteWhere('absence_requests', 'id', [$this->own->getId(), $this->decided->getId()], IQueryBuilder::PARAM_INT_ARRAY);
		$this->deleteWhere('absence_comments', 'request_id', [$this->own->getId()], IQueryBuilder::PARAM_INT_ARRAY);
		$this->deleteWhere('absence_holidays', 'region', [self::REGION], IQueryBuilder::PARAM_STR_ARRAY);
		$this->deleteWhere('absence_holiday_history', 'detail', ['{"region":"' . self::REGION . '"}'], IQueryBuilder::PARAM_STR_ARRAY);
		$this->deleteWhere('absence_employee_history', 'employee_uid', [self::OTHER], IQueryBuilder::PARAM_STR_ARRAY);
		parent::tearDown();
	}

	public function testTheDeletedUserIsNoLongerNamedOnWhatTheyDecidedOrEdited(): void {
		Server::get(IUserManager::class)->get(self::GONE)->delete();

		self::assertSame([null], $this->column('absence_requests', 'decided_by', 'id', $this->decided->getId()));
		self::assertSame([null], $this->column('absence_holidays', 'created_by', 'region', self::REGION));
		self::assertSame([null], $this->column('absence_holidays', 'updated_by', 'region', self::REGION));
		self::assertSame([null], $this->column('absence_holiday_history', 'actor_uid', 'detail', '{"region":"' . self::REGION . '"}'));
		self::assertSame([null], $this->column('absence_employee_history', 'actor_uid', 'employee_uid', self::OTHER));
	}

	public function testNotificationsAboutTheirRequestsAreTakenBackFromEveryone(): void {
		Server::get(IUserManager::class)->get(self::GONE)->delete();

		$taken = array_map(
			static fn (INotification $n): array => [$n->getApp(), $n->getUser(), $n->getObjectType(), $n->getObjectId()],
			RecordingNotifications::$processed,
		);
		self::assertContains(['absence', '', 'absence_request', (string)$this->own->getId()], $taken);
		self::assertNotContains(['absence', '', 'absence_request', (string)$this->decided->getId()], $taken);
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
		$listener = new UserDeletedListener(
			$this->db,
			Server::get(LeaveRequestMapper::class),
			Server::get(CalendarService::class),
			Server::get(AttachmentService::class),
			Server::get(LoggerInterface::class),
			$employees,
			Server::get(RequestService::class),
			Server::get(NotificationService::class),
			Server::get(IEventDispatcher::class),
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::GONE);

		try {
			$listener->handle(new UserDeletedEvent($user));
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
