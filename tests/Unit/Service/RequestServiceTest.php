<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Db\RequestCommentMapper;
use OCA\Absence\Db\RequestEventMapper;
use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\ActivityPublisher;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\CalendarService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\CoverageService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Service\NoticeService;
use OCA\Absence\Service\NotificationService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\RequestService;
use OCA\Absence\Tests\Unit\ClockMockTrait;
use OCA\Absence\Tests\Unit\L10nMockTrait;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RequestServiceTest extends TestCase {
	use ClockMockTrait;
	use L10nMockTrait;

	private LeaveRequestMapper&MockObject $requestMapper;
	private RequestCommentMapper&MockObject $commentMapper;
	private RequestEventMapper&MockObject $eventMapper;
	private LeaveTypeMapper&MockObject $leaveTypeMapper;
	private EmployeeDirectory&MockObject $employees;
	/** @var string[] uids the directory should report as guests for a given test */
	private array $guestUids = [];
	private ManagerResolver&MockObject $managerResolver;
	private PermissionService&MockObject $permission;
	private CoverageService&MockObject $coverage;
	private BalanceService&MockObject $balances;
	private NoticeService&MockObject $notice;
	private CalendarService&MockObject $calendar;
	private NotificationService&MockObject $notifications;
	private ActivityPublisher&MockObject $activity;
	private ConfigService&MockObject $config;
	private IUserManager&MockObject $userManager;
	private LoggerInterface&MockObject $logger;
	private IDBConnection&MockObject $db;
	private ILockingProvider&MockObject $lockingProvider;
	private RequestService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->lockingProvider = $this->createMock(ILockingProvider::class);
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);
		$this->commentMapper = $this->createMock(RequestCommentMapper::class);
		$this->eventMapper = $this->createMock(RequestEventMapper::class);
		$this->leaveTypeMapper = $this->createMock(LeaveTypeMapper::class);
		$this->managerResolver = $this->createMock(ManagerResolver::class);
		$this->permission = $this->createMock(PermissionService::class);
		$this->coverage = $this->createMock(CoverageService::class);
		$this->balances = $this->createMock(BalanceService::class);
		$this->balances->method('getBalance')->willReturn(['balances' => []]);
		$this->notice = $this->createMock(NoticeService::class);
		$this->calendar = $this->createMock(CalendarService::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->activity = $this->createMock(ActivityPublisher::class);
		$this->config = $this->createMock(ConfigService::class);
		// Everyone is an employee unless a test says otherwise, so the existing
		// cases keep testing what they were written to test.
		$this->employees = $this->createMock(EmployeeDirectory::class);
		$this->employees->method('isEmployee')->willReturnCallback(
			fn (string $uid): bool => !in_array($uid, $this->guestUids, true),
		);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->rebuildService();
	}

	/**
	 * Rebuild the service against the current mocks.
	 *
	 * Called from setUp(), and again by any test that needs to reprogram a
	 * collaborator with expects() — those cannot be layered onto a mock the
	 * constructor has already been handed.
	 */
	private function rebuildService(): void {
		$this->service = new RequestService(
			$this->requestMapper,
			$this->commentMapper,
			$this->eventMapper,
			$this->leaveTypeMapper,
			$this->managerResolver,
			$this->permission,
			$this->clockAtRealTime(),
			$this->coverage,
			$this->balances,
			$this->notice,
			$this->calendar,
			$this->notifications,
			$this->activity,
			$this->config,
			$this->employees,
			$this->userManager,
			$this->l10nMock(),
			$this->logger,
			$this->db,
			$this->lockingProvider,
		);
	}

	private function type(int $id, bool $employeeRequestable): LeaveType {
		$type = new LeaveType();
		$type->setId($id);
		$type->setKey('t' . $id);
		$type->setLabel('Type ' . $id);
		$type->setEnabled(true);
		$type->setEmployeeRequestable($employeeRequestable);
		return $type;
	}

	private function pendingOwnRequest(): LeaveRequest {
		$request = new LeaveRequest();
		$request->setId(5);
		$request->setEmployeeUid('emp');
		$request->setTypeId(1);
		$request->setStartDate('2026-02-10');
		$request->setEndDate('2026-02-12');
		$request->setWorkingDays(3.0);
		$request->setStatus(LeaveRequest::STATUS_PENDING);
		return $request;
	}

	public function testHrCannotRecordLeaveForAGuest(): void {
		// Guests are external accounts with no entitlement, so there is no leave to
		// record for them — not even by HR, who may record for anyone else (§2.2).
		$this->guestUids = ['ext'];
		$this->leaveTypeMapper->method('find')->with(1)->willReturn($this->type(1, true));
		$this->permission->method('isHr')->with('hr')->willReturn(true);

		$this->requestMapper->expects(self::never())->method('insert');

		$this->expectException(ValidationException::class);
		$this->service->create('hr', [
			'typeId' => 1,
			'startDate' => '2026-03-02',
			'endDate' => '2026-03-03',
			'workingDays' => 2.0,
			'employeeUid' => 'ext',
		]);
	}

	public function testAGuestCannotBeNominatedAsReplacement(): void {
		// Cover during an absence is a colleague's duty; a guest cannot take it on.
		// The guest is a perfectly real account — "does this uid exist" would wave it
		// through, so only the employee check can reject it.
		$this->guestUids = ['ext'];
		$this->userManager->method('userExists')->willReturn(true);
		$type = $this->type(1, true);
		$type->setRequiresReplacement(true);
		$this->leaveTypeMapper->method('find')->with(1)->willReturn($type);
		$this->permission->method('isHr')->with('emp')->willReturn(false);

		$this->requestMapper->expects(self::never())->method('insert');

		// Dated forward off the test clock: leave entirely in the past is rejected
		// before the replacement is ever resolved, which would pass this test for
		// the wrong reason (and start doing so silently as the year rolls over).
		$start = date('Y-m-d', strtotime('+30 days'));
		$this->expectException(ValidationException::class);
		$this->service->create('emp', [
			'typeId' => 1,
			'startDate' => $start,
			'endDate' => $start,
			'workingDays' => 1.0,
			'replacementUid' => 'ext',
		]);
	}

	public function testDetailCarriesTheEmployeeBalanceForTheYearTheLeaveStartsIn(): void {
		// Seeing that somebody took three days says nothing about whether they have
		// any left; the detail view is where that question gets answered now.
		$request = $this->pendingOwnRequest();
		$this->requestMapper->method('find')->with(5)->willReturn($request);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canViewBalanceOf')->with('hr', 'emp')->willReturn(true);
		$this->commentMapper->method('findForRequest')->willReturn([]);
		$this->eventMapper->method('findForRequest')->willReturn([]);

		// The request starts in 2026, so 2026's allowance is the relevant one.
		$this->balances = $this->createMock(BalanceService::class);
		$this->balances->expects(self::once())->method('getBalance')->with('emp', 2026)
			->willReturn(['balances' => [
				['typeId' => 9, 'entitlement' => 10.0, 'used' => 1.0, 'pending' => 0.0, 'remaining' => 9.0, 'available' => 9.0],
				['typeId' => 1, 'entitlement' => 28.0, 'used' => 6.0, 'pending' => 3.0, 'remaining' => 22.0, 'available' => 19.0],
			]]);
		$this->rebuildService();

		$detail = $this->service->getDetail('hr', 5);

		self::assertSame(2026, $detail['balance']['year']);
		self::assertSame(22.0, $detail['balance']['remaining']);
		self::assertSame(19.0, $detail['balance']['available']);
	}

	public function testDetailWithholdsTheBalanceFromSomeoneWhoMayNotSeeIt(): void {
		$request = $this->pendingOwnRequest();
		$this->requestMapper->method('find')->with(5)->willReturn($request);
		$this->permission->method('canView')->willReturn(true);
		// A colleague may read the request without being entitled to the allowance.
		$this->permission->method('canViewBalanceOf')->willReturn(false);
		$this->commentMapper->method('findForRequest')->willReturn([]);
		$this->eventMapper->method('findForRequest')->willReturn([]);

		$this->balances->expects(self::never())->method('getBalance');

		self::assertArrayNotHasKey('balance', $this->service->getDetail('peer', 5));
	}

	public function testDetailHasNoBalanceForLeaveThatCountsAgainstNothing(): void {
		$request = $this->pendingOwnRequest();
		$this->requestMapper->method('find')->with(5)->willReturn($request);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canViewBalanceOf')->willReturn(true);
		$this->commentMapper->method('findForRequest')->willReturn([]);
		$this->eventMapper->method('findForRequest')->willReturn([]);

		// Unpaid and special leave have no ceiling, so "how many are left" has no answer.
		$this->balances = $this->createMock(BalanceService::class);
		$this->balances->method('getBalance')->willReturn(['balances' => [
			['typeId' => 1, 'entitlement' => null, 'used' => 2.0, 'pending' => 0.0, 'remaining' => null, 'available' => null],
		]]);
		$this->rebuildService();

		self::assertNull($this->service->getDetail('hr', 5)['balance']);
	}

	public function testApplyingForOwnLeaveStillDemandsAReplacement(): void {
		// §5.1 unchanged for self-service: the employee knows who can cover and is
		// asked to arrange it before going.
		$type = $this->type(1, true);
		$type->setRequiresReplacement(true);
		$this->leaveTypeMapper->method('find')->with(1)->willReturn($type);
		$this->permission->method('isHr')->with('emp')->willReturn(false);

		$this->requestMapper->expects(self::never())->method('insert');

		$start = date('Y-m-d', strtotime('+30 days'));
		$this->expectException(ValidationException::class);
		$this->service->create('emp', [
			'typeId' => 1,
			'startDate' => $start,
			'endDate' => $start,
			'workingDays' => 1.0,
		]);
	}

	public function testHrRecordingForSomeoneElseNeedsNoReplacement(): void {
		// HR is stating a fact about somebody else's absence, often after it happened,
		// and cannot nominate cover on their behalf — so the type's requirement does
		// not apply to them.
		$type = $this->type(1, true);
		$type->setRequiresReplacement(true);
		$this->leaveTypeMapper->method('find')->with(1)->willReturn($type);
		$this->permission->method('isHr')->with('hr')->willReturn(true);
		$this->requestMapper->method('findOverlapping')->willReturn([]);
		$this->requestMapper->method('insert')->willReturnArgument(0);

		$start = date('Y-m-d', strtotime('+30 days'));
		$created = $this->service->create('hr', [
			'typeId' => 1,
			'employeeUid' => 'emp',
			'startDate' => $start,
			'endDate' => $start,
			'workingDays' => 1.0,
		]);

		self::assertNull($created->getReplacementUid());
		self::assertSame('emp', $created->getEmployeeUid());
	}

	public function testAReplacementIsValidatedEvenWhenTheTypeDoesNotDemandOne(): void {
		// Only the *demand* is conditional. Skipping the checks when the type happened
		// not to require a replacement let a guest be recorded as covering.
		$this->guestUids = ['ext'];
		$this->userManager->method('userExists')->willReturn(true);
		$type = $this->type(1, true);
		$type->setRequiresReplacement(false);
		$this->leaveTypeMapper->method('find')->with(1)->willReturn($type);
		$this->permission->method('isHr')->with('emp')->willReturn(false);

		$this->requestMapper->expects(self::never())->method('insert');

		$start = date('Y-m-d', strtotime('+30 days'));
		$this->expectException(ValidationException::class);
		$this->service->create('emp', [
			'typeId' => 1,
			'startDate' => $start,
			'endDate' => $start,
			'workingDays' => 1.0,
			'replacementUid' => 'ext',
		]);
	}

	public function testEmployeeCannotReclassifyIntoHrOnlyType(): void {
		$request = $this->pendingOwnRequest();
		$this->requestMapper->method('find')->with(5)->willReturn($request);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canModify')->willReturn(true);
		$this->permission->method('isHr')->with('emp')->willReturn(false);
		// Target type 9 is HR-recorded (not self-requestable).
		$this->leaveTypeMapper->method('find')->with(9)->willReturn($this->type(9, false));

		// The reclassification must be rejected and nothing persisted.
		$this->requestMapper->expects(self::never())->method('update');

		$this->expectException(ForbiddenException::class);
		$this->service->update('emp', 5, ['typeId' => 9]);
	}

	public function testApprovingWithdrawalNotifiesReplacement(): void {
		// Approved leave with a nominated replacement, now awaiting withdrawal approval.
		$request = $this->pendingOwnRequest();
		$request->setStatus(LeaveRequest::STATUS_WITHDRAWAL_PENDING);
		$request->setManagerUid('mgr');
		$request->setReplacementUid('rep');
		$this->requestMapper->method('find')->with(5)->willReturn($request);
		$this->requestMapper->method('update')->willReturnArgument(0);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canDecide')->willReturn(true);

		// The replacement was told they cover (§5.1) — withdrawing must tell them to stop.
		$this->notifications->expects(self::once())->method('notifyReplacementCancelled');
		$this->calendar->expects(self::once())->method('onRemoved');

		$result = $this->service->approve('mgr', 5, null);
		self::assertSame(LeaveRequest::STATUS_CANCELLED, $result->getStatus());
	}

	public function testRejectingWithdrawalSendsWithdrawalRejectedNotification(): void {
		$request = $this->pendingOwnRequest();
		$request->setStatus(LeaveRequest::STATUS_WITHDRAWAL_PENDING);
		$request->setManagerUid('mgr');
		$this->requestMapper->method('find')->with(5)->willReturn($request);
		$this->requestMapper->method('update')->willReturnArgument(0);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canDecide')->willReturn(true);

		// A declined withdrawal is not an approval — no "your leave was approved 🎉".
		$this->notifications->expects(self::once())->method('notifyWithdrawalRejected');
		$this->notifications->expects(self::never())->method('notifyDecision');

		$result = $this->service->reject('mgr', 5, 'We need you that week');
		self::assertSame(LeaveRequest::STATUS_APPROVED, $result->getStatus());
	}

	public function testSecondSupersedingEditIsRejected(): void {
		$original = $this->pendingOwnRequest();
		$original->setStatus(LeaveRequest::STATUS_APPROVED);
		$this->requestMapper->method('find')->with(5)->willReturn($original);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canModify')->willReturn(true);
		$this->permission->method('isHr')->willReturn(false);

		// One edit is already in flight for this approved request.
		$pendingEdit = new LeaveRequest();
		$pendingEdit->setId(6);
		$pendingEdit->setSupersedesId(5);
		$pendingEdit->setStatus(LeaveRequest::STATUS_PENDING);
		$this->requestMapper->method('findBySupersedesId')->with(5)->willReturn([$pendingEdit]);

		// A second one must not be created: both could be approved and overlap.
		$this->requestMapper->expects(self::never())->method('insert');

		$this->expectException(\OCA\Absence\Exception\ConflictException::class);
		$this->service->update('emp', 5, ['startDate' => '2026-03-02', 'endDate' => '2026-03-04']);
	}

	public function testWithdrawalIsRefusedWhileAnEditIsPending(): void {
		$original = $this->pendingOwnRequest();
		$original->setStatus(LeaveRequest::STATUS_APPROVED);
		$this->requestMapper->method('find')->with(5)->willReturn($original);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canModify')->willReturn(true);
		$this->permission->method('isHr')->willReturn(false);

		// An edit of this approved leave is already awaiting a decision.
		$pendingEdit = new LeaveRequest();
		$pendingEdit->setId(6);
		$pendingEdit->setSupersedesId(5);
		$pendingEdit->setStatus(LeaveRequest::STATUS_PENDING);
		$this->requestMapper->method('findBySupersedesId')->with(5)->willReturn([$pendingEdit]);

		// Moving the original to WITHDRAWAL_PENDING here used to be allowed. Approving
		// the edit would then find the original no longer APPROVED, decline to retire
		// it, and leave the same leave counted twice — as used by the edit and as
		// pending by the original.
		$this->requestMapper->expects(self::never())->method('update');
		$this->notifications->expects(self::never())->method('notifyWithdrawal');

		$this->expectException(\OCA\Absence\Exception\ConflictException::class);
		$this->service->cancel('emp', 5);
	}

	public function testApprovingAnEditRetiresAnOriginalAwaitingWithdrawal(): void {
		// The pair the guard above now prevents can still exist in rows written before
		// it, so approving the edit has to close the original rather than walk past it.
		$edit = $this->pendingOwnRequest();
		$edit->setId(6);
		$edit->setSupersedesId(5);
		$edit->setManagerUid('mgr');

		$original = $this->pendingOwnRequest();
		$original->setStatus(LeaveRequest::STATUS_WITHDRAWAL_PENDING);

		$this->requestMapper->method('find')->willReturnMap([[6, $edit], [5, $original]]);
		$this->requestMapper->method('update')->willReturnArgument(0);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canDecide')->willReturn(true);

		$result = $this->service->approve('mgr', 6, null);

		self::assertSame(LeaveRequest::STATUS_APPROVED, $result->getStatus());
		self::assertSame(LeaveRequest::STATUS_CANCELLED, $original->getStatus(),
			'the superseded original must not stay in force alongside the approved edit');
	}

	// ------------------------------------------------ atomicity and locking ----

	public function testApprovingAnEditRetiresTheOriginalInOneTransaction(): void {
		// The whole point: approving the edit and cancelling the request it
		// supersedes must commit together, or the employee ends up holding two
		// overlapping approved requests and is charged for both.
		$original = $this->pendingOwnRequest();
		$original->setId(5);
		$original->setStatus(LeaveRequest::STATUS_APPROVED);

		$edit = $this->pendingOwnRequest();
		$edit->setId(6);
		$edit->setSupersedesId(5);
		$edit->setStatus(LeaveRequest::STATUS_PENDING);

		$this->requestMapper->method('find')->willReturnMap([[6, $edit], [5, $original]]);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canDecide')->willReturn(true);
		$this->requestMapper->method('update')->willReturnArgument(0);

		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('commit');
		$this->db->expects(self::never())->method('rollBack');

		$this->service->approve('boss', 6, null);

		$this->assertSame(LeaveRequest::STATUS_APPROVED, $edit->getStatus());
		$this->assertSame(LeaveRequest::STATUS_CANCELLED, $original->getStatus());
	}

	public function testReplacingTheReplacementReleasesThePreviousOne(): void {
		// retireSuperseded() cancels the original with a direct write, so it never
		// passes through transitionToCancelled() where the replacement is normally
		// released. Without this the colleague who agreed to cover the old dates is
		// left believing they still do, while somebody else is told they cover.
		$original = $this->pendingOwnRequest();
		$original->setId(5);
		$original->setStatus(LeaveRequest::STATUS_APPROVED);
		$original->setReplacementUid('ada');

		$edit = $this->pendingOwnRequest();
		$edit->setId(6);
		$edit->setSupersedesId(5);
		$edit->setStatus(LeaveRequest::STATUS_PENDING);
		$edit->setReplacementUid('grace');

		$this->requestMapper->method('find')->willReturnMap([[6, $edit], [5, $original]]);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canDecide')->willReturn(true);
		$this->requestMapper->method('update')->willReturnArgument(0);

		$released = null;
		$this->notifications->method('notifyReplacementCancelled')
			->willReturnCallback(function (LeaveRequest $r) use (&$released): void {
				$released = $r->getReplacementUid();
			});

		$this->service->approve('boss', 6, null);

		$this->assertSame('ada', $released);
	}

	public function testKeepingTheSameReplacementDoesNotNotifyThem(): void {
		// They still cover. "No longer covering" immediately followed by "you are
		// covering" is noise, not information.
		$original = $this->pendingOwnRequest();
		$original->setId(5);
		$original->setStatus(LeaveRequest::STATUS_APPROVED);
		$original->setReplacementUid('ada');

		$edit = $this->pendingOwnRequest();
		$edit->setId(6);
		$edit->setSupersedesId(5);
		$edit->setStatus(LeaveRequest::STATUS_PENDING);
		$edit->setReplacementUid('ada');

		$this->requestMapper->method('find')->willReturnMap([[6, $edit], [5, $original]]);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canDecide')->willReturn(true);
		$this->requestMapper->method('update')->willReturnArgument(0);

		$this->notifications->expects(self::never())->method('notifyReplacementCancelled');

		$this->service->approve('boss', 6, null);
	}

	public function testAFailedWriteRollsTheTransactionBack(): void {
		$edit = $this->pendingOwnRequest();
		$edit->setId(6);
		$edit->setStatus(LeaveRequest::STATUS_PENDING);
		$this->requestMapper->method('find')->with(6)->willReturn($edit);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canDecide')->willReturn(true);
		$this->requestMapper->method('update')->willThrowException(new \RuntimeException('database is on fire'));

		$this->db->expects(self::once())->method('beginTransaction');
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::never())->method('commit');
		// A rolled-back approval must not tell anybody their leave was approved.
		$this->notifications->expects(self::never())->method('notifyDecision');

		$this->expectException(\RuntimeException::class);
		$this->service->approve('boss', 6, null);
	}

	public function testDecidingReleasesTheEmployeeLockEvenWhenTheWriteFails(): void {
		// A leaked lock would block every later change to this employee's leave.
		$edit = $this->pendingOwnRequest();
		$edit->setId(6);
		$edit->setStatus(LeaveRequest::STATUS_PENDING);
		$this->requestMapper->method('find')->with(6)->willReturn($edit);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canDecide')->willReturn(true);
		$this->requestMapper->method('update')->willThrowException(new \RuntimeException('nope'));

		$this->lockingProvider->expects(self::once())->method('acquireLock');
		$this->lockingProvider->expects(self::once())->method('releaseLock');

		$this->expectException(\RuntimeException::class);
		$this->service->approve('boss', 6, null);
	}

	public function testACompetingWriteOnTheSameEmployeeIsRejectedAsAConflict(): void {
		$edit = $this->pendingOwnRequest();
		$edit->setId(6);
		$edit->setStatus(LeaveRequest::STATUS_PENDING);
		$this->requestMapper->method('find')->with(6)->willReturn($edit);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canDecide')->willReturn(true);
		$this->lockingProvider->method('acquireLock')
			->willThrowException(new \OCP\Lock\LockedException('absence/employee/emp'));

		// Rather than proceeding on a stale read of the employee's other requests.
		$this->requestMapper->expects(self::never())->method('update');

		$this->expectException(\OCA\Absence\Exception\ConflictException::class);
		$this->service->approve('boss', 6, null);
	}

	public function testAddCommentRejectsOverlongBody(): void {
		$request = $this->pendingOwnRequest();
		$this->requestMapper->method('find')->with(5)->willReturn($request);
		$this->permission->method('canView')->willReturn(true);
		$this->commentMapper->expects(self::never())->method('insert');

		$this->expectException(ValidationException::class);
		$this->service->addComment('emp', 5, str_repeat('a', 4001));
	}

	public function testAddCommentNotifiesTheEmployeeAndTheManager(): void {
		// A comment is otherwise only visible to whoever thinks to open the Comments
		// tab, so a manager's question could sit unread until the request expired.
		$request = $this->pendingOwnRequest();
		$request->setManagerUid('boss');
		$this->requestMapper->method('find')->with(5)->willReturn($request);
		$this->permission->method('canView')->willReturn(true);
		$this->commentMapper->method('insert')->willReturnArgument(0);
		// Not escalated, so HR is not dragged into a conversation they are not part of.
		$this->permission->expects(self::never())->method('getHrUids');

		$this->notifications->expects(self::once())->method('notifyComment')
			->with($request, 'boss', 'Can you move this a day later?', ['emp', 'boss']);

		$this->service->addComment('boss', 5, 'Can you move this a day later?');
	}

	public function testAddCommentOnAnEscalatedRequestAlsoReachesHr(): void {
		// Once HR has been pulled in they are a party to the discussion — a question
		// they asked has to come back to them, not just to the line manager.
		$request = $this->pendingOwnRequest();
		$request->setManagerUid('boss');
		$request->setEscalated(true);
		$this->requestMapper->method('find')->with(5)->willReturn($request);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('getHrUids')->willReturn(['hr1', 'hr2']);
		$this->commentMapper->method('insert')->willReturnArgument(0);

		$this->notifications->expects(self::once())->method('notifyComment')
			->with($request, 'emp', 'Any news on this?', ['emp', 'boss', 'hr1', 'hr2']);

		$this->service->addComment('emp', 5, 'Any news on this?');
	}

	public function testDecliningAWithdrawalPassesTheReasonToTheEmployee(): void {
		// The reason is recorded as a comment, not in decision_comment, so unless it
		// travels with the notification the employee is told "declined" and nothing more.
		$request = $this->pendingOwnRequest();
		$request->setManagerUid('boss');
		$request->setStatus(LeaveRequest::STATUS_WITHDRAWAL_PENDING);
		$this->requestMapper->method('find')->with(5)->willReturn($request);
		$this->requestMapper->method('update')->willReturnArgument(0);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canDecide')->willReturn(true);

		$this->notifications->expects(self::once())->method('notifyWithdrawalRejected')
			->with($request, 'We have nobody to cover that week.', 'boss');

		$this->service->reject('boss', 5, 'We have nobody to cover that week.');
	}

	public function testWorkingDaysMayNotExceedTheCalendarSpan(): void {
		// §7: the count is entered by hand, so 40 typed for 4 must be a clean 422
		// instead of a wrecked balance. Three calendar days hold at most three
		// working days.
		$type = $this->type(1, true);
		$this->leaveTypeMapper->method('find')->with(1)->willReturn($type);
		$this->permission->method('isHr')->with('emp')->willReturn(false);
		$this->requestMapper->expects(self::never())->method('insert');

		$start = date('Y-m-d', strtotime('+30 days'));
		$end = date('Y-m-d', strtotime('+32 days'));
		$this->expectException(ValidationException::class);
		$this->service->create('emp', [
			'typeId' => 1,
			'startDate' => $start,
			'endDate' => $end,
			'workingDays' => 4.0,
		]);
	}

	public function testAFullSpanOfWorkingDaysIsAccepted(): void {
		// The boundary is inclusive: every calendar day a working day is legitimate.
		$type = $this->type(1, true);
		$this->leaveTypeMapper->method('find')->with(1)->willReturn($type);
		$this->permission->method('isHr')->with('emp')->willReturn(false);
		$this->requestMapper->method('findOverlapping')->willReturn([]);
		$this->requestMapper->method('insert')->willReturnArgument(0);

		$start = date('Y-m-d', strtotime('+30 days'));
		$end = date('Y-m-d', strtotime('+32 days'));
		$created = $this->service->create('emp', [
			'typeId' => 1,
			'startDate' => $start,
			'endDate' => $end,
			'workingDays' => 3.0,
		]);

		self::assertSame(3.0, $created->getWorkingDays());
	}

	public function testEscalationYieldsToAConcurrentDecision(): void {
		// The job picked this request from a list read moments earlier; by the time
		// it acts, the manager has decided it. The conditional flip refuses, and no
		// notification announces an escalation that never happened.
		$request = $this->pendingOwnRequest();
		$this->requestMapper->method('markEscalated')->willReturn(false);
		$this->requestMapper->expects(self::never())->method('update');
		$this->notifications->expects(self::never())->method('notifyEscalation');

		$this->service->escalate($request);

		self::assertSame(LeaveRequest::STATUS_PENDING, $request->getStatus());
	}

	public function testEscalationFlipsAndNotifiesWhenStillPending(): void {
		$request = $this->pendingOwnRequest();
		$this->requestMapper->method('markEscalated')->with(5)->willReturn(true);
		$this->permission->method('getHrUids')->willReturn(['hr1']);
		$this->notifications->expects(self::once())->method('notifyEscalation')
			->with($request, ['hr1']);

		$this->service->escalate($request);

		self::assertSame(LeaveRequest::STATUS_ESCALATED, $request->getStatus());
		self::assertTrue($request->getEscalated());
	}

	public function testAConfidentialAbsenceIsWithheldFromItsOwnEmployee(): void {
		// §5.7: the employee sees that the absence exists — dates and status —
		// but not the category, the reason or anything HR wrote about it.
		$request = $this->pendingOwnRequest();
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		$request->setTypeId(9);
		$request->setReason('Maternity leave until March');
		$this->requestMapper->method('find')->willReturn($request);
		$this->requestMapper->method('findFiltered')->willReturn([$request]);
		$this->leaveTypeMapper->method('hrOnlyTypeIds')->willReturn([9]);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('isHr')->with('emp')->willReturn(false);
		$this->permission->method('isHrOnlyRequest')->willReturn(true);

		$detail = $this->service->getDetail('emp', 5);
		self::assertNull($detail['typeId']);
		self::assertNull($detail['reason']);
		self::assertSame([], $detail['history']);
		self::assertSame([], $detail['comments']);
		self::assertFalse($detail['canModify']);

		$rows = $this->service->listSerialized('emp', ['scope' => 'mine'], null, null);
		self::assertNull($rows[0]['typeId']);
		self::assertNull($rows[0]['reason']);
		self::assertSame($request->getStartDate(), $rows[0]['startDate']);
	}

	public function testAConfidentialAbsenceIsFullyVisibleToHr(): void {
		$request = $this->pendingOwnRequest();
		$request->setTypeId(9);
		$request->setReason('Maternity leave until March');
		$this->requestMapper->method('findFiltered')->willReturn([$request]);
		$this->leaveTypeMapper->method('hrOnlyTypeIds')->willReturn([9]);
		$this->permission->method('isHr')->with('hr')->willReturn(true);

		$rows = $this->service->listSerialized('hr', ['scope' => 'hr'], null, null);
		self::assertSame(9, $rows[0]['typeId']);
		self::assertSame('Maternity leave until March', $rows[0]['reason']);
	}

	public function testOnlyHrCanSetTheDisabilityFlag(): void {
		// §5.8: a crafted self-service payload must not set it — and must not
		// fail the request either; the flag is simply ignored.
		$type = $this->type(1, true);
		$this->leaveTypeMapper->method('find')->with(1)->willReturn($type);
		$this->permission->method('isHr')->with('emp')->willReturn(false);
		$this->requestMapper->method('findOverlapping')->willReturn([]);
		$this->requestMapper->method('insert')->willReturnArgument(0);

		$start = date('Y-m-d', strtotime('+30 days'));
		$created = $this->service->create('emp', [
			'typeId' => 1,
			'startDate' => $start,
			'endDate' => $start,
			'workingDays' => 1.0,
			'replacementUid' => 'aisha',
			'disability' => true,
		]);

		self::assertFalse($created->getDisability());
	}

	public function testHrSetsTheDisabilityFlagWhenRecording(): void {
		$type = $this->type(1, true);
		$this->leaveTypeMapper->method('find')->with(1)->willReturn($type);
		$this->permission->method('isHr')->with('hr')->willReturn(true);
		$this->requestMapper->method('findOverlapping')->willReturn([]);
		$this->requestMapper->method('insert')->willReturnArgument(0);

		$start = date('Y-m-d', strtotime('+30 days'));
		$created = $this->service->create('hr', [
			'typeId' => 1,
			'employeeUid' => 'emp',
			'startDate' => $start,
			'endDate' => $start,
			'workingDays' => 1.0,
			'disability' => true,
		]);

		self::assertTrue($created->getDisability());
	}

	public function testTheDisabilityFlagIsWithheldFromNonHrViewers(): void {
		$request = $this->pendingOwnRequest();
		$request->setDisability(true);
		$this->requestMapper->method('findFiltered')->willReturn([$request]);
		$this->leaveTypeMapper->method('hrOnlyTypeIds')->willReturn([]);
		$this->permission->method('isHr')->willReturnCallback(
			static fn (string $uid): bool => $uid === 'hr',
		);

		$own = $this->service->listSerialized('emp', ['scope' => 'mine'], null, null);
		self::assertNull($own[0]['disability']);

		$hr = $this->service->listSerialized('hr', ['scope' => 'hr'], null, null);
		self::assertTrue($hr[0]['disability']);
	}
}
