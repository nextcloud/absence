<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Api\AbsenceProvider;
use OCA\Absence\BackgroundJob\EscalationJob;
use OCA\Absence\BackgroundJob\ReminderJob;
use OCA\Absence\BackgroundJob\WorkingDaysTrait;
use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Db\RequestCommentMapper;
use OCA\Absence\Db\RequestEventMapper;
use OCA\Absence\Service\ActivityPublisher;
use OCA\Absence\Service\AttachmentService;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\CalendarService;
use OCA\Absence\Service\CarryOverReminderService;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\CoverageService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Service\NoticeService;
use OCA\Absence\Service\NotificationService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\PersonalDefaultsService;
use OCA\Absence\Service\RequestService;
use OCA\Absence\Service\SessionService;
use OCA\Absence\Tests\Integration\Fixtures\RecordingNotifications;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Notification\INotification;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A request belongs to the manager its employee has today (§2.1), against a
 * real server: what a manager lists, is asked to decide and is told about
 * follows a change of manager, and a manager who cannot act is passed over.
 */
class ManagerChangeTest extends TestCase {
	use WorkingDaysTrait;

	private const EMPLOYEE = 'itest-managers-emp';
	private const FORMER = 'itest-managers-former';
	private const CURRENT = 'itest-managers-current';
	private const HR = 'itest-managers-hr';
	private const HR_GROUP = 'itest-managers-hr';
	private const TYPE_KEY = 'itest-managers';

	private LeaveRequestMapper $mapper;
	private int $typeId = 0;
	/** @var array<string,string> */
	private array $configBefore = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = Server::get(LeaveRequestMapper::class);
		RecordingNotifications::register();
		$users = Server::get(IUserManager::class);
		foreach ([self::EMPLOYEE, self::FORMER, self::CURRENT] as $uid) {
			$users->createUser($uid, 'Itest-Password-managers-' . $uid);
		}
		Server::get(IGroupManager::class)->createGroup(self::HR_GROUP)
			->addUser($users->createUser(self::HR, 'Itest-Password-managers-hr'));
		$users->get(self::EMPLOYEE)->setManagerUids([self::FORMER]);

		$config = Server::get(ConfigService::class);
		$this->configBefore = [
			ConfigLexicon::KEY_HR_GROUP => $config->getHrGroup(),
			ConfigLexicon::KEY_EMPLOYEES_GROUP => $config->getEmployeesGroup(),
		];
		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, self::HR_GROUP);
		$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_EMPLOYEES_GROUP, '');

		$type = new LeaveType();
		$type->setKey(self::TYPE_KEY);
		$type->setLabel('itest managers');
		$type->setCountsAgainstBalance(false);
		$this->typeId = Server::get(LeaveTypeMapper::class)->insert($type)->getId();
	}

	protected function tearDown(): void {
		Server::get(IUserSession::class)->setUser(null);
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from('absence_requests')
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter(self::EMPLOYEE)));
		$requestIds = array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		foreach (['absence_comments', 'absence_request_events'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in('request_id', $qb->createNamedParameter($requestIds, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('absence_requests')->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter(self::EMPLOYEE)))->executeStatement();
		$qb = $db->getQueryBuilder();
		$qb->delete('absence_leave_types')->where($qb->expr()->eq('key', $qb->createNamedParameter(self::TYPE_KEY)))->executeStatement();
		foreach ([self::EMPLOYEE, self::FORMER, self::CURRENT, self::HR] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		Server::get(IGroupManager::class)->get(self::HR_GROUP)?->delete();
		foreach ($this->configBefore as $key => $value) {
			Server::get(IAppConfig::class)->setValueString(ConfigService::APP_ID, $key, $value);
		}
		parent::tearDown();
	}

	public function testARequestFollowsItsEmployeeToTheNewManager(): void {
		$request = $this->seed(LeaveRequest::STATUS_PENDING);
		self::assertSame([$request->getId()], $this->listedFor(self::FORMER));
		self::assertSame(1, $this->pendingApprovalsOf(self::FORMER));

		Server::get(IUserManager::class)->get(self::EMPLOYEE)->setManagerUids([self::CURRENT]);

		self::assertSame([], $this->listedFor(self::FORMER));
		self::assertSame(0, $this->pendingApprovalsOf(self::FORMER));
		self::assertSame([$request->getId()], $this->listedFor(self::CURRENT));
		self::assertSame(1, $this->pendingApprovalsOf(self::CURRENT));
	}

	public function testCommentsAndWithdrawalsAreSentToTheNewManager(): void {
		$pending = $this->seed(LeaveRequest::STATUS_PENDING);
		$approved = $this->seed(LeaveRequest::STATUS_APPROVED, '2027-07-05', '2027-07-09');
		Server::get(IUserManager::class)->get(self::EMPLOYEE)->setManagerUids([self::CURRENT]);
		$service = $this->requestService($this->resolver());

		$service->addComment(self::EMPLOYEE, $pending->getId(), 'Could this be decided this week?');
		$service->cancel(self::EMPLOYEE, $approved->getId());

		self::assertSame([self::CURRENT], $this->recipients(NotificationService::SUBJECT_COMMENT, $pending));
		self::assertEqualsCanonicalizing([self::CURRENT, self::HR], $this->recipients(NotificationService::SUBJECT_WITHDRAWAL, $approved));
	}

	public function testAnEditedRequestIsSentToTheNewManager(): void {
		$request = $this->seed(LeaveRequest::STATUS_PENDING);
		Server::get(IUserManager::class)->get(self::EMPLOYEE)->setManagerUids([self::CURRENT]);

		$this->requestService($this->resolver())->update(self::EMPLOYEE, $request->getId(), ['reason' => 'Moving house.']);

		self::assertSame([self::CURRENT], $this->recipients(NotificationService::SUBJECT_NEW_REQUEST, $request));
	}

	public function testTheReminderIsSentToTheNewManager(): void {
		$config = Server::get(ConfigService::class);
		$threshold = max(1, max(1, $config->getEscalationWindowDays()) - max(0, $config->getReminderLeadDays()));
		$today = new \DateTimeImmutable('2026-11-13 00:00:00', new \DateTimeZone('UTC'));
		$clock = $this->createMock(ClockService::class);
		$clock->method('serverNow')->willReturn($today->setTime(12, 0));
		// Made on the day the reminder for it falls due on this run
		$request = $this->seed(LeaveRequest::STATUS_PENDING, created: $this->subtractWorkingDays($today, $threshold)->modify('-12 hours'));
		Server::get(IUserManager::class)->get(self::EMPLOYEE)->setManagerUids([self::CURRENT]);

		$appConfig = Server::get(IAppConfig::class);
		$appConfig->deleteKey(ConfigService::APP_ID, ConfigLexicon::KEY_REMINDER_CUTOFF);
		$job = new ReminderJob(Server::get(ITimeFactory::class), $clock, $this->mapper, Server::get(NotificationService::class),
			$config, $appConfig, Server::get(LoggerInterface::class), $this->resolver(), Server::get(CarryOverReminderService::class));
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
		$appConfig->deleteKey(ConfigService::APP_ID, ConfigLexicon::KEY_REMINDER_CUTOFF);

		self::assertSame([self::CURRENT], $this->recipients(NotificationService::SUBJECT_REMINDER, $request));
	}

	public function testARequestGoesStraightToHrWhenTheManagerIsDisabled(): void {
		Server::get(IUserManager::class)->get(self::FORMER)->setEnabled(false);

		$request = $this->requestService($this->resolver())->create(self::EMPLOYEE, [
			'typeId' => $this->typeId,
			'startDate' => '2027-08-02',
			'endDate' => '2027-08-06',
			'workingDays' => 5.0,
		]);

		self::assertSame(LeaveRequest::STATUS_ESCALATED, $request->getStatus());
		self::assertNull($request->getManagerUid());
		self::assertSame([self::HR], $this->allRecipients());
	}

	public function testAPendingRequestGoesToHrOnceItsManagerIsDisabled(): void {
		$request = $this->seed(LeaveRequest::STATUS_PENDING);
		Server::get(IUserManager::class)->get(self::FORMER)->setEnabled(false);
		$resolver = $this->resolver();

		$job = new EscalationJob(Server::get(ITimeFactory::class), Server::get(ClockService::class), $this->mapper,
			$this->requestService($resolver), Server::get(ConfigService::class), Server::get(LoggerInterface::class), $resolver);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);

		self::assertSame(LeaveRequest::STATUS_ESCALATED, $this->mapper->find($request->getId())->getStatus());
		self::assertSame([self::HR], $this->allRecipients());
	}

	public function testADisabledAccountIsNotNotified(): void {
		$request = $this->seed(LeaveRequest::STATUS_PENDING);
		Server::get(IUserManager::class)->get(self::FORMER)->setEnabled(false);

		Server::get(NotificationService::class)->notifyReminder($request, self::FORMER);

		self::assertSame([], $this->allRecipients());
	}

	public function testAnEscalationIsNotSentToThePersonItIsAbout(): void {
		Server::get(IGroupManager::class)->get(self::HR_GROUP)->addUser(Server::get(IUserManager::class)->get(self::EMPLOYEE));
		$request = $this->seed(LeaveRequest::STATUS_PENDING);

		$this->requestService($this->resolver())->escalate($request);

		self::assertSame([self::HR], $this->recipients(NotificationService::SUBJECT_ESCALATION, $request));
	}

	private function seed(string $status, string $start = '2027-06-07', string $end = '2027-06-11', ?\DateTimeImmutable $created = null): LeaveRequest {
		$created = \DateTime::createFromImmutable($created ?? new \DateTimeImmutable('-1 hour'));
		$request = new LeaveRequest();
		$request->setEmployeeUid(self::EMPLOYEE);
		// Who the manager was when the request was made
		$request->setManagerUid(self::FORMER);
		$request->setTypeId($this->typeId);
		$request->setStartDate($start);
		$request->setEndDate($end);
		$request->setWorkingDays(5.0);
		$request->setStatus($status);
		$request->setCreatedAt($created);
		$request->setUpdatedAt($created);
		return $this->mapper->insert($request);
	}

	/**
	 * The resolver and the directory under it remember what they read for the
	 * length of a web request, so each step that follows a change in the user
	 * directory gets its own.
	 */
	private function resolver(): ManagerResolver {
		return new ManagerResolver(Server::get(IUserManager::class), Server::get(IUserConfig::class), $this->directory(), Server::get(LoggerInterface::class));
	}

	private function directory(): EmployeeDirectory {
		return new EmployeeDirectory(Server::get(IUserManager::class), Server::get(IGroupManager::class), Server::get(ConfigService::class), Server::get(LoggerInterface::class));
	}

	private function permission(ManagerResolver $resolver): PermissionService {
		return new PermissionService(Server::get(IGroupManager::class), $resolver, Server::get(ConfigService::class),
			Server::get(LeaveTypeMapper::class), Server::get(IFactory::class)->get('absence'), Server::get(LoggerInterface::class));
	}

	private function requestService(ManagerResolver $resolver): RequestService {
		return new RequestService($this->mapper, Server::get(RequestCommentMapper::class), Server::get(RequestEventMapper::class),
			Server::get(LeaveTypeMapper::class), $resolver, $this->permission($resolver), Server::get(ClockService::class),
			Server::get(CoverageService::class), Server::get(BalanceService::class), Server::get(NoticeService::class),
			Server::get(CalendarService::class), Server::get(NotificationService::class), Server::get(ActivityPublisher::class),
			Server::get(ConfigService::class), $this->directory(), Server::get(AttachmentService::class), Server::get(IUserManager::class),
			Server::get(IFactory::class)->get('absence'), Server::get(LoggerInterface::class), Server::get(IDBConnection::class),
			Server::get(ILockingProvider::class), Server::get(AbsenceProvider::class), Server::get(IEventDispatcher::class));
	}

	/**
	 * @return list<int> the requests in the manager's "reports" list
	 */
	private function listedFor(string $managerUid): array {
		$requests = $this->requestService($this->resolver())->list($managerUid, ['scope' => 'reports'], null, null);
		return array_map(static fn (LeaveRequest $request): int => $request->getId(), $requests);
	}

	private function pendingApprovalsOf(string $uid): int {
		Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
		$resolver = $this->resolver();
		return (new SessionService(Server::get(IUserSession::class), Server::get(IUserManager::class), $this->permission($resolver), $this->directory(),
			$resolver, $this->mapper, Server::get(PersonalDefaultsService::class), Server::get(ConfigService::class)))->getSessionInfo()['pendingApprovals'];
	}

	/**
	 * @return list<string> who was sent this notification about the request
	 */
	private function recipients(string $subject, LeaveRequest $request): array {
		$sent = array_filter(RecordingNotifications::$sent, static fn (INotification $n): bool => $n->getSubject() === $subject
			&& $n->getObjectId() === (string)$request->getId());
		return array_values(array_map(static fn (INotification $n): string => $n->getUser(), $sent));
	}

	/**
	 * @return list<string> everybody who was sent anything
	 */
	private function allRecipients(): array {
		return array_values(array_unique(array_map(static fn (INotification $n): string => $n->getUser(), RecordingNotifications::$sent)));
	}
}
