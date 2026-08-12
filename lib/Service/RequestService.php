<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Db\RequestComment;
use OCA\Absence\Db\RequestCommentMapper;
use OCA\Absence\Db\RequestEvent;
use OCA\Absence\Db\RequestEventMapper;
use OCA\Absence\Exception\ConflictException;
use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Exception\NotFoundException;
use OCA\Absence\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * The workflow orchestrator: enforces the request state machine (§4) and the
 * apply / review / edit / withdraw / escalate flows (§5), coordinating balances,
 * calendar, notifications and activity.
 *
 * Two rules hold throughout:
 *
 *  - Every sequence that writes more than one row runs inside a transaction, so
 *    a half-applied workflow cannot survive a failure. The dangerous case is
 *    approving an edit: the new request is approved and the request it
 *    supersedes is retired, and if only the first half landed the employee would
 *    hold two overlapping approved requests, both counted against the balance.
 *  - Anything that decides "may this be written?" by reading first — the overlap
 *    check above all — runs while holding {@see withEmployeeLock()} for that
 *    employee, because the check and the write are otherwise two steps that a
 *    concurrent request can interleave.
 *
 * External side effects (calendar, notifications, activity) deliberately happen
 * *after* the transaction commits: they are not rollback-able, so firing them
 * from inside would announce leave that the database then refused.
 */
class RequestService {
	use TTransactional;

	/** Cap on free-text fields to keep rows bounded and prevent storage abuse. */
	private const MAX_REASON_LENGTH = 2000;
	private const MAX_COMMENT_LENGTH = 4000;

	public function __construct(
		private LeaveRequestMapper $requestMapper,
		private RequestCommentMapper $commentMapper,
		private RequestEventMapper $eventMapper,
		private LeaveTypeMapper $leaveTypeMapper,
		private ManagerResolver $managerResolver,
		private PermissionService $permission,
		private ClockService $clock,
		private CoverageService $coverage,
		private NoticeService $notice,
		private CalendarService $calendar,
		private NotificationService $notifications,
		private ActivityPublisher $activity,
		private ConfigService $config,
		private EmployeeDirectory $employees,
		private \OCP\IUserManager $userManager,
		private LoggerInterface $logger,
		private IDBConnection $db,
		private ILockingProvider $lockingProvider,
	) {
	}

	/**
	 * Run $fn while holding an exclusive lock on one employee's requests.
	 *
	 * The overlap rule ("no two requests covering the same day") cannot be
	 * expressed as a database constraint — it is a range comparison, not an
	 * equality — so it has to be enforced by reading before writing. That read
	 * and the following write are only safe together if nothing else is writing
	 * for the same employee at the same time, which is what this serialises.
	 * Scoped per employee so unrelated people never wait on each other.
	 *
	 * The provider does not queue: if someone else holds the lock this fails
	 * immediately rather than blocking a web request, and the caller is asked to
	 * retry. Losing the race is rare and retrying is cheap, whereas holding a PHP
	 * worker open waiting for a lock is not.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 * @throws ConflictException if a competing write holds the lock
	 */
	private function withEmployeeLock(string $employeeUid, callable $fn): mixed {
		$key = 'absence/employee/' . $employeeUid;
		try {
			$this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE, 'leave of ' . $employeeUid);
		} catch (LockedException) {
			throw new ConflictException('Another change to this leave is being processed. Please try again.');
		}
		try {
			return $fn();
		} finally {
			$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Validate the nominated replacement for a leave type that requires one (§5.1).
	 * Returns the (trimmed) replacement uid, or null when not required.
	 *
	 * @throws ValidationException
	 */
	private function resolveReplacement(string $employeeUid, LeaveType $type, ?string $replacementUid): ?string {
		if (!$type->getRequiresReplacement()) {
			return $replacementUid !== null && trim($replacementUid) !== '' ? trim($replacementUid) : null;
		}
		$replacementUid = trim((string)$replacementUid);
		if ($replacementUid === '') {
			throw new ValidationException('Please choose a replacement for this leave.');
		}
		if ($replacementUid === $employeeUid) {
			throw new ValidationException('You cannot be your own replacement.');
		}
		// Also rejects guests: cover during an absence is a colleague's duty (§2.2).
		if (!$this->employees->isEmployee($replacementUid)) {
			throw new ValidationException('The chosen replacement is not a valid user.');
		}
		return $replacementUid;
	}

	// ---------------------------------------------------------------- reads ----

	/**
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	public function get(string $actorUid, int $id): LeaveRequest {
		try {
			$request = $this->requestMapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Request not found');
		}
		if (!$this->permission->canView($actorUid, $request)) {
			throw new ForbiddenException('Not allowed to view this request');
		}
		return $request;
	}

	/**
	 * Detailed view: request + comments + coverage (when the actor may decide).
	 *
	 * @return array<string,mixed>
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	public function getDetail(string $actorUid, int $id): array {
		$request = $this->get($actorUid, $id);
		$detail = $request->jsonSerialize();
		$detail['comments'] = array_map(
			static fn (RequestComment $c) => $c->jsonSerialize(),
			$this->commentMapper->findForRequest($id),
		);
		$detail['history'] = array_map(
			static fn (RequestEvent $e) => $e->jsonSerialize(),
			$this->eventMapper->findForRequest($id),
		);
		$detail['canDecide'] = $this->permission->canDecide($actorUid, $request);
		$detail['canModify'] = $this->permission->canModify($actorUid, $request);
		$detail = $this->withDisplayNames($detail, $request);
		if ($detail['canDecide']) {
			$detail['coverage'] = $this->coverage->getRequestCoverage($request, $actorUid);
			// Only for someone who may decide, like the coverage summary: it is there to
			// inform a decision, and the employee already knows how late they asked.
			$detail['shortNotice'] = $this->notice->warningFor($request);
		}
		return $detail;
	}

	/**
	 * Serialize a list of requests, resolving display names so the client can
	 * name people instead of printing uids.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function listSerialized(string $actorUid, array $filters, ?int $limit, ?int $offset): array {
		return array_map(
			fn (LeaveRequest $r) => $this->withDisplayNames($r->jsonSerialize(), $r),
			$this->list($actorUid, $filters, $limit, $offset),
		);
	}

	/**
	 * Add the display names for the people on a serialized request, falling back
	 * to the uid when an account no longer exists.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function withDisplayNames(array $data, LeaveRequest $request): array {
		// The employee's name is always attached: managers and HR spend most of
		// their time in this app looking at *other people's* leave, and a raw uid
		// is not who they are looking for.
		$data['employeeName'] = $this->displayName($request->getEmployeeUid());
		if ($request->getReplacementUid() !== null) {
			$data['replacementName'] = $this->displayName($request->getReplacementUid());
		}
		return $data;
	}

	/** A user's display name, falling back to the uid for a deleted account. */
	private function displayName(string $uid): string {
		$user = $this->userManager->get($uid);
		return $user !== null ? $user->getDisplayName() : $uid;
	}

	/**
	 * List requests scoped by role (own / reports / all for HR).
	 *
	 * @param array<string,mixed> $filters
	 * @return LeaveRequest[]
	 */
	public function list(string $actorUid, array $filters, ?int $limit, ?int $offset): array {
		$scope = (string)($filters['scope'] ?? 'mine');
		$query = [];
		if (isset($filters['status'])) {
			$query['status'] = (string)$filters['status'];
		}
		if (isset($filters['typeId'])) {
			$query['typeId'] = (int)$filters['typeId'];
		}
		if (isset($filters['from'])) {
			$query['from'] = (string)$filters['from'];
		}
		if (isset($filters['to'])) {
			$query['to'] = (string)$filters['to'];
		}

		if ($scope === 'reports' || $scope === 'approvals') {
			$query['managerUid'] = $actorUid;
			return $this->requestMapper->findFiltered($query, $limit, $offset);
		}
		if ($scope === 'hr') {
			$this->permission->assertHr($actorUid);
			if (!empty($filters['employeeUid'])) {
				$query['employeeUid'] = (string)$filters['employeeUid'];
			}
			return $this->requestMapper->findFiltered($query, $limit, $offset);
		}
		// Default: the actor's own requests.
		$query['employeeUid'] = $actorUid;
		return $this->requestMapper->findFiltered($query, $limit, $offset);
	}

	// -------------------------------------------------------------- create ----

	/**
	 * Apply for leave (§5.1).
	 *
	 * @param array{typeId:int,startDate:string,endDate:string,reason?:?string,attachmentNote?:?string,employeeUid?:?string,workingDays?:?float,replacementUid?:?string} $data
	 * @throws ValidationException|ConflictException|ForbiddenException
	 */
	public function create(string $actorUid, array $data): LeaveRequest {
		$type = $this->resolveType((int)($data['typeId'] ?? 0));

		// Whose leave this is: HR may record leave on behalf of another employee.
		$employeeUid = trim((string)($data['employeeUid'] ?? '')) ?: $actorUid;
		$onBehalf = $employeeUid !== $actorUid;
		$isHr = $this->permission->isHr($actorUid);
		if ($onBehalf && !$isHr) {
			throw new ForbiddenException('Only HR can record leave for another employee.');
		}
		// Guests are external accounts, not staff: they have no entitlement and take
		// no leave, so no record may be created for one — including by HR (§2.2).
		if (!$this->employees->isEmployee($employeeUid)) {
			throw new ValidationException('Leave can only be recorded for an employee.');
		}
		// Some types (e.g. sick leave) are recorded by HR, not self-requested (§5.6).
		if (!$type->getEmployeeRequestable() && !$isHr) {
			throw new ForbiddenException('This leave type is recorded by HR, not self-requested.');
		}

		$start = $this->normaliseDate((string)($data['startDate'] ?? ''));
		$end = $this->normaliseDate((string)($data['endDate'] ?? ''));
		$this->validateRange($actorUid, $start, $end, $type, (string)($data['reason'] ?? ''), (string)($data['attachmentNote'] ?? ''));
		$replacementUid = $this->resolveReplacement($employeeUid, $type, $data['replacementUid'] ?? null);

		// The employee enters the number of working days; the manager verifies it (§7).
		$workingDays = $this->normaliseWorkingDays($data['workingDays'] ?? null);

		$managerUid = $this->managerResolver->getManagerUid($employeeUid);
		$now = $this->clock->now();

		// The overlap check only means anything if nothing else can insert for this
		// employee between it and the insert below.
		$request = $this->withEmployeeLock($employeeUid, fn (): LeaveRequest => $this->atomic(function () use (
			$actorUid, $employeeUid, $onBehalf, $type, $start, $end, $workingDays, $replacementUid, $managerUid, $now, $data,
		): LeaveRequest {
			$this->assertNoOverlap($employeeUid, $start, $end);

			$request = new LeaveRequest();
			$request->setEmployeeUid($employeeUid);
			$request->setManagerUid($managerUid);
			$request->setTypeId($type->getId());
			$request->setStartDate($start);
			$request->setEndDate($end);
			$request->setWorkingDays($workingDays);
			$request->setReason($data['reason'] ?? null);
			$request->setReplacementUid($replacementUid);
			$request->setAttachmentNote($data['attachmentNote'] ?? null);
			$request->setCreatedAt($now);
			$request->setUpdatedAt($now);

			// Non-requestable types, auto-approve types, and any HR-recorded leave are
			// booked straight to APPROVED with no approval workflow (§4.1, §5.6).
			$recordedDirectly = !$type->getEmployeeRequestable() || !$type->getRequiresApproval() || $onBehalf;
			if ($recordedDirectly) {
				$request->setStatus(LeaveRequest::STATUS_APPROVED);
				$request->setDecidedBy($actorUid);
				$request->setDecidedAt($now);
				$request->setEscalated(false);
			} elseif ($managerUid === null) {
				$request->setStatus(LeaveRequest::STATUS_ESCALATED);
				$request->setEscalated(true);
			} else {
				$request->setStatus(LeaveRequest::STATUS_PENDING);
				$request->setEscalated(false);
			}

			return $this->requestMapper->insert($request);
		}, $this->db));

		// Side effects by resulting status. After the commit — see the class docblock.
		if ($request->getStatus() === LeaveRequest::STATUS_APPROVED) {
			$this->applyCalendar($request);
			$this->notifications->notifyReplacementAssigned($request);
			$this->activity->publish(ActivityPublisher::SUBJECT_CREATED, $this->activityParams($request), [$employeeUid, $actorUid], $request);
		} elseif ($request->getStatus() === LeaveRequest::STATUS_ESCALATED) {
			$hrUids = $this->permission->getHrUids();
			$this->notifications->notifyEscalation($request, $hrUids);
			$this->activity->publish(ActivityPublisher::SUBJECT_CREATED, $this->activityParams($request), [$employeeUid, ...$hrUids], $request);
		} else {
			$this->notifications->notifyNewRequest($request, (string)$managerUid);
			$this->activity->publish(ActivityPublisher::SUBJECT_CREATED, $this->activityParams($request), [$employeeUid, (string)$managerUid], $request);
		}
		// `reason` is the employee's own note on the request; the log entry is the one
		// place it can be reconstructed later, so it goes into the context rather than
		// into `detail` (the Details tab already shows it in the request's history).
		$this->audit('request_created', $request, [
			'actor' => $actorUid,
			'detail' => $this->createdDetail($request, $type, $onBehalf),
			'reason' => $request->getReason(),
		]);
		return $request;
	}

	private function createdDetail(LeaveRequest $request, LeaveType $type, bool $onBehalf): ?string {
		// Lead with what was actually asked for. The history is the one place the
		// original ask survives: the request itself only ever shows its *current*
		// state, so once HR corrects the dates or the reason, nothing else can say
		// what the leave was booked for in the first place.
		$parts = [$type->getLabel() . ', ' . $this->describeRange($request) . ' (' . $this->days($request->getWorkingDays()) . ')'];
		$reason = trim((string)$request->getReason());
		if ($reason !== '') {
			$parts[] = 'Reason: ' . $this->quote($reason);
		}
		$how = match (true) {
			!$type->getEmployeeRequestable() => 'Recorded by HR',
			$onBehalf => 'Recorded by HR on behalf',
			$request->getStatus() === LeaveRequest::STATUS_APPROVED => 'Automatically approved',
			$request->getStatus() === LeaveRequest::STATUS_ESCALATED => 'No line manager — routed to HR',
			default => null,
		};
		if ($how !== null) {
			$parts[] = $how;
		}
		return implode(' · ', $parts);
	}

	/**
	 * The fields an edit may change, captured before it does.
	 *
	 * @return array<string,mixed>
	 */
	private function snapshot(LeaveRequest $request): array {
		return [
			'typeId' => $request->getTypeId(),
			'startDate' => $request->getStartDate(),
			'endDate' => $request->getEndDate(),
			'workingDays' => $request->getWorkingDays(),
			'reason' => (string)$request->getReason(),
			'replacementUid' => (string)$request->getReplacementUid(),
		];
	}

	/**
	 * What actually changed, field by field, as a sentence for the history timeline.
	 *
	 * Recording the resulting state instead — "adjusted to 2 – 6 March (5 days)" —
	 * is what the timeline used to do, and it cannot answer the question anybody
	 * opens the history to ask: not what the request says now (the request itself
	 * says that), but what somebody changed and by how much. A day count especially
	 * means nothing without the number it replaced.
	 *
	 * @param array<string,mixed> $before from {@see snapshot()}
	 * @return ?string null when nothing observable changed
	 */
	private function describeChanges(array $before, LeaveRequest $after): ?string {
		$parts = [];
		if ($before['typeId'] !== $after->getTypeId()) {
			$parts[] = 'Type ' . $this->typeLabel((int)$before['typeId']) . ' → ' . $this->typeLabel($after->getTypeId());
		}
		if ($before['startDate'] !== $after->getStartDate() || $before['endDate'] !== $after->getEndDate()) {
			$parts[] = 'Dates ' . $this->range((string)$before['startDate'], (string)$before['endDate'])
				. ' → ' . $this->describeRange($after);
		}
		$wasDays = (float)$before['workingDays'];
		$nowDays = $after->getWorkingDays();
		if (abs($wasDays - $nowDays) > 0.001) {
			$delta = $nowDays - $wasDays;
			$parts[] = 'Working days ' . $this->days($wasDays) . ' → ' . $this->days($nowDays)
				. ' (' . ($delta > 0 ? '+' : '−') . $this->days(abs($delta)) . ')';
		}
		$wasReason = trim((string)$before['reason']);
		$nowReason = trim((string)$after->getReason());
		if ($wasReason !== $nowReason) {
			$parts[] = $nowReason === ''
				? 'Reason cleared'
				: ($wasReason === '' ? 'Reason: ' . $this->quote($nowReason)
					: 'Reason ' . $this->quote($wasReason) . ' → ' . $this->quote($nowReason));
		}
		$wasRep = (string)$before['replacementUid'];
		$nowRep = (string)$after->getReplacementUid();
		if ($wasRep !== $nowRep) {
			$parts[] = $nowRep === ''
				? 'Replacement removed (' . $this->displayName($wasRep) . ')'
				: ($wasRep === '' ? 'Replacement: ' . $this->displayName($nowRep)
					: 'Replacement ' . $this->displayName($wasRep) . ' → ' . $this->displayName($nowRep));
		}
		return $parts === [] ? null : implode('; ', $parts);
	}

	private function describeRange(LeaveRequest $request): string {
		return $this->range($request->getStartDate(), $request->getEndDate());
	}

	private function range(string $start, string $end): string {
		return $start === $end ? $start : $start . ' – ' . $end;
	}

	/** A day count without a trailing `.0`, so "5 days" rather than "5.0 days". */
	private function days(float $value): string {
		$formatted = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
		return $formatted . ($formatted === '1' ? ' day' : ' days');
	}

	private function quote(string $text): string {
		return '“' . $text . '”';
	}

	private function typeLabel(int $typeId): string {
		try {
			return $this->leaveTypeMapper->find($typeId)->getLabel();
		} catch (DoesNotExistException) {
			return 'type #' . $typeId;
		}
	}

	// ---------------------------------------------------------------- edit ----

	/**
	 * Edit a request (§5.3). Behaviour depends on the current status and actor role.
	 *
	 * @param array<string,mixed> $data
	 */
	public function update(string $actorUid, int $id, array $data): LeaveRequest {
		$request = $this->get($actorUid, $id);
		if (!$this->permission->canModify($actorUid, $request)) {
			throw new ForbiddenException('Not allowed to edit this request');
		}
		$isHr = $this->permission->isHr($actorUid);

		// HR override: edit in place on any status, keeping calendar in sync. This is
		// also the only edit path for HR-recorded leave (e.g. sick), even the HR
		// member's own record — the employee path below would reject it (§5.6).
		if ($isHr && ($actorUid !== $request->getEmployeeUid() || $this->isHrRecordedType($request))) {
			return $this->hrEdit($actorUid, $request, $data);
		}

		$status = $request->getStatus();
		if (in_array($status, [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_ESCALATED], true)) {
			return $this->editInPlace($actorUid, $request, $data);
		}
		if ($status === LeaveRequest::STATUS_APPROVED) {
			return $this->createSuperseding($actorUid, $request, $data);
		}
		throw new ConflictException('This request can no longer be edited.');
	}

	private function editInPlace(string $actorUid, LeaveRequest $request, array $data): LeaveRequest {
		$type = $this->resolveType((int)($data['typeId'] ?? $request->getTypeId()));
		$this->assertSelfRequestable($type);
		$start = $this->normaliseDate((string)($data['startDate'] ?? $request->getStartDate()));
		$end = $this->normaliseDate((string)($data['endDate'] ?? $request->getEndDate()));
		$this->validateRange($actorUid, $start, $end, $type, (string)($data['reason'] ?? $request->getReason() ?? ''), (string)($data['attachmentNote'] ?? $request->getAttachmentNote() ?? ''));

		$replacementUid = $this->resolveReplacement($request->getEmployeeUid(), $type, $data['replacementUid'] ?? $request->getReplacementUid());

		// Captured before the entity is mutated below — afterwards the old values are gone.
		$before = $this->snapshot($request);

		$request = $this->withEmployeeLock($request->getEmployeeUid(), fn (): LeaveRequest => $this->atomic(function () use (
			$request, $type, $start, $end, $replacementUid, $data,
		): LeaveRequest {
			$this->assertNoOverlap($request->getEmployeeUid(), $start, $end, $this->chainExcludeIds($request));

			$request->setTypeId($type->getId());
			$request->setStartDate($start);
			$request->setEndDate($end);
			$request->setWorkingDays($this->normaliseWorkingDays($data['workingDays'] ?? $request->getWorkingDays()));
			$request->setReplacementUid($replacementUid);
			if (array_key_exists('reason', $data)) {
				$request->setReason($data['reason']);
			}
			if (array_key_exists('attachmentNote', $data)) {
				$request->setAttachmentNote($data['attachmentNote']);
			}
			$request->setUpdatedAt($this->clock->now());
			return $this->requestMapper->update($request);
		}, $this->db));

		// Re-notify the decider that the request changed.
		if ($request->getStatus() === LeaveRequest::STATUS_ESCALATED) {
			$this->notifications->notifyEscalation($request, $this->permission->getHrUids());
		} elseif ($request->getManagerUid() !== null) {
			$this->notifications->notifyNewRequest($request, $request->getManagerUid());
		}
		$this->audit('request_updated', $request, ['actor' => $actorUid, 'detail' => $this->describeChanges($before, $request)]);
		return $request;
	}

	private function createSuperseding(string $actorUid, LeaveRequest $original, array $data): LeaveRequest {
		// Cheap up-front check so the caller gets the useful "there is already a
		// pending edit" message rather than a complaint about whatever they changed.
		// Authoritative re-check happens under the lock below.
		$this->assertNoPendingEdit($original);

		$type = $this->resolveType((int)($data['typeId'] ?? $original->getTypeId()));
		$this->assertSelfRequestable($type);
		$start = $this->normaliseDate((string)($data['startDate'] ?? $original->getStartDate()));
		$end = $this->normaliseDate((string)($data['endDate'] ?? $original->getEndDate()));
		$this->validateRange($actorUid, $start, $end, $type, (string)($data['reason'] ?? ''), (string)($data['attachmentNote'] ?? ''));
		$replacementUid = $this->resolveReplacement($actorUid, $type, $data['replacementUid'] ?? $original->getReplacementUid());

		$now = $this->clock->now();
		$managerUid = $this->managerResolver->getManagerUid($actorUid);

		$new = $this->withEmployeeLock($actorUid, fn (): LeaveRequest => $this->atomic(function () use (
			$actorUid, $original, $type, $start, $end, $replacementUid, $managerUid, $now, $data,
		): LeaveRequest {
			// Re-checked under the lock: two concurrent edits would otherwise both
			// find no sibling in the fast check above and both insert.
			$this->assertNoPendingEdit($original);
			// The original still occupies its dates; exclude it and its chain from the check.
			$this->assertNoOverlap($actorUid, $start, $end, $this->chainExcludeIds($original));

			$new = new LeaveRequest();
			$new->setEmployeeUid($actorUid);
			$new->setManagerUid($managerUid);
			$new->setTypeId($type->getId());
			$new->setStartDate($start);
			$new->setEndDate($end);
			$new->setWorkingDays($this->normaliseWorkingDays($data['workingDays'] ?? $original->getWorkingDays()));
			$new->setReason($data['reason'] ?? null);
			$new->setReplacementUid($replacementUid);
			$new->setAttachmentNote($data['attachmentNote'] ?? null);
			$new->setSupersedesId($original->getId());
			$new->setStatus($managerUid === null ? LeaveRequest::STATUS_ESCALATED : LeaveRequest::STATUS_PENDING);
			$new->setEscalated($managerUid === null);
			$new->setCreatedAt($now);
			$new->setUpdatedAt($now);
			return $this->requestMapper->insert($new);
		}, $this->db));

		if ($new->getStatus() === LeaveRequest::STATUS_ESCALATED) {
			$this->notifications->notifyEscalation($new, $this->permission->getHrUids());
		} else {
			$this->notifications->notifyNewRequest($new, (string)$managerUid);
		}
		$this->activity->publish(ActivityPublisher::SUBJECT_CREATED, $this->activityParams($new), [$actorUid, (string)$managerUid], $new);
		$this->audit('request_edited_superseding', $new, ['actor' => $actorUid, 'supersedes' => $original->getId(), 'detail' => 'Edit of approved leave, pending re-approval']);
		return $new;
	}

	private function hrEdit(string $actorUid, LeaveRequest $request, array $data): LeaveRequest {
		$wasApproved = $request->getStatus() === LeaveRequest::STATUS_APPROVED;
		// Before the setters below overwrite them; the history reports the difference.
		$before = $this->snapshot($request);
		if (isset($data['typeId'])) {
			$request->setTypeId($this->resolveType((int)$data['typeId'])->getId());
		}
		if (isset($data['startDate'])) {
			$request->setStartDate($this->normaliseDate((string)$data['startDate']));
		}
		if (isset($data['endDate'])) {
			$request->setEndDate($this->normaliseDate((string)$data['endDate']));
		}
		if (array_key_exists('reason', $data)) {
			if ($data['reason'] !== null && mb_strlen((string)$data['reason']) > self::MAX_REASON_LENGTH) {
				throw new ValidationException('The reason is too long.');
			}
			$request->setReason($data['reason']);
		}
		if (array_key_exists('replacementUid', $data)) {
			// Not resolveType(): that also rejects a disabled type, and HR must stay able
			// to correct a historical request whose type has since been retired. Only the
			// type's replacement rule is wanted here.
			try {
				$type = $this->leaveTypeMapper->find($request->getTypeId());
			} catch (DoesNotExistException) {
				throw new ValidationException('This request refers to a leave type that no longer exists.');
			}
			$request->setReplacementUid($this->resolveReplacement($request->getEmployeeUid(), $type, $data['replacementUid']));
		}
		// HR may correct the working-day count (§5.5); otherwise it is kept as entered.
		if (array_key_exists('workingDays', $data) && $data['workingDays'] !== null) {
			$request->setWorkingDays($this->normaliseWorkingDays($data['workingDays']));
		}
		if ($request->getEndDate() < $request->getStartDate()) {
			throw new ValidationException('The end date must be on or after the start date.');
		}
		$request = $this->withEmployeeLock($request->getEmployeeUid(), fn (): LeaveRequest => $this->atomic(function () use ($request): LeaveRequest {
			$this->assertNoOverlap($request->getEmployeeUid(), $request->getStartDate(), $request->getEndDate(), $this->chainExcludeIds($request));
			$request->setUpdatedAt($this->clock->now());
			return $this->requestMapper->update($request);
		}, $this->db));

		if ($wasApproved) {
			// Rebuild the calendar entry for the new dates.
			$this->calendar->onRemoved($request);
			$this->applyCalendar($request);
		}
		$this->activity->publish(ActivityPublisher::SUBJECT_CREATED, $this->activityParams($request), [$request->getEmployeeUid()], $request);
		$this->audit('request_hr_edited', $request, ['actor' => $actorUid, 'detail' => $this->describeChanges($before, $request)]);
		return $request;
	}

	// -------------------------------------------------------------- cancel ----

	/**
	 * Cancel a pending request outright, or request withdrawal of an approved one
	 * (§5.3). HR can force-cancel anything (§5.5).
	 */
	public function cancel(string $actorUid, int $id): LeaveRequest {
		$request = $this->get($actorUid, $id);
		if (!$this->permission->canModify($actorUid, $request)) {
			throw new ForbiddenException('Not allowed to cancel this request');
		}
		$status = $request->getStatus();
		// HR cancels directly (no withdrawal step) for others' requests and for
		// HR-recorded leave — including their own, which has no approval workflow (§5.6).
		$isHrOverride = $this->permission->isHr($actorUid)
			&& ($actorUid !== $request->getEmployeeUid() || $this->isHrRecordedType($request));

		if (in_array($status, LeaveRequest::TERMINAL_STATUSES, true)) {
			throw new ConflictException('This request is already closed.');
		}

		if (in_array($status, [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_ESCALATED], true)) {
			return $this->transitionToCancelled($actorUid, $request);
		}

		if ($status === LeaveRequest::STATUS_APPROVED) {
			if ($isHrOverride) {
				return $this->transitionToCancelled($actorUid, $request);
			}
			// Not while an edit of this leave is awaiting a decision (§5.3). The edit
			// excludes the original from its overlap check as part of the supersedes
			// chain, and retireSuperseded() only retires an original that is still
			// APPROVED — so moving this one to WITHDRAWAL_PENDING first and then
			// approving the edit would leave both in force, the same leave counted
			// twice, and a declined withdrawal would put two overlapping approved
			// requests on the same dates. Cancel the edit first, then withdraw.
			$this->assertNoPendingEdit($request);
			// Employee: approved leave requires a withdrawal approval step.
			$request = $this->atomic(function () use ($request): LeaveRequest {
				$request->setStatus(LeaveRequest::STATUS_WITHDRAWAL_PENDING);
				$request->setUpdatedAt($this->clock->now());
				return $this->requestMapper->update($request);
			}, $this->db);
			$recipients = array_filter([$request->getManagerUid(), ...$this->permission->getHrUids()]);
			$this->notifications->notifyWithdrawal($request, array_values($recipients));
			$this->activity->publish(ActivityPublisher::SUBJECT_WITHDRAWAL, $this->activityParams($request), [$request->getEmployeeUid(), ...$recipients], $request);
			$this->audit('withdrawal_requested', $request, ['actor' => $actorUid]);
			return $request;
		}

		if ($status === LeaveRequest::STATUS_WITHDRAWAL_PENDING && $isHrOverride) {
			return $this->transitionToCancelled($actorUid, $request);
		}
		throw new ConflictException('This request cannot be cancelled in its current state.');
	}

	private function transitionToCancelled(string $actorUid, LeaveRequest $request, string $action = 'request_cancelled'): LeaveRequest {
		// If an already-approved leave is being cancelled, the replacement no longer
		// covers. WITHDRAWAL_PENDING counts too: it is approved leave awaiting a
		// withdrawal decision, so the replacement was told they cover (§5.1).
		$wasApproved = in_array($request->getStatus(), [
			LeaveRequest::STATUS_APPROVED,
			LeaveRequest::STATUS_WITHDRAWAL_PENDING,
		], true);
		$request = $this->atomic(function () use ($actorUid, $request): LeaveRequest {
			$now = $this->clock->now();
			$request->setStatus(LeaveRequest::STATUS_CANCELLED);
			$request->setDecidedBy($actorUid);
			$request->setDecidedAt($now);
			$request->setUpdatedAt($now);
			return $this->requestMapper->update($request);
		}, $this->db);
		// Only drop the calendar entry once the cancellation is durably committed —
		// removing it first would strand the employee's calendar if the update failed.
		$this->calendar->onRemoved($request);
		$this->notifications->dismiss($request);
		if ($wasApproved) {
			$this->notifications->notifyReplacementCancelled($request);
		}
		$this->activity->publish(ActivityPublisher::SUBJECT_CANCELLED, $this->activityParams($request), [$request->getEmployeeUid(), (string)$request->getManagerUid()], $request);
		$this->audit($action, $request, ['actor' => $actorUid]);
		return $request;
	}

	// ------------------------------------------------------------- decisions ----

	public function approve(string $actorUid, int $id, ?string $comment): LeaveRequest {
		if ($comment !== null && mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
			throw new ValidationException('Comment is too long.');
		}
		$request = $this->get($actorUid, $id);
		if (!$this->permission->canDecide($actorUid, $request)) {
			throw new ForbiddenException('Not allowed to decide this request');
		}
		$status = $request->getStatus();

		if (in_array($status, [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_ESCALATED], true)) {
			// Approving an edit both approves the new request and retires the one it
			// supersedes. If only the first half committed, the employee would hold two
			// overlapping approved requests and be charged for both — so the two writes
			// are one transaction, taken under the employee's lock so a concurrent edit
			// cannot slip a new sibling in between.
			[$request, $retired] = $this->withEmployeeLock(
				$request->getEmployeeUid(),
				fn (): array => $this->atomic(function () use ($actorUid, $request, $comment): array {
					$now = $this->clock->now();
					$request->setStatus(LeaveRequest::STATUS_APPROVED);
					$request->setDecidedBy($actorUid);
					$request->setDecidedAt($now);
					$request->setDecisionComment($comment);
					$request->setUpdatedAt($now);
					$request = $this->requestMapper->update($request);
					return [$request, $this->retireSuperseded($request)];
				}, $this->db),
			);

			// Calendar work follows the commit; see the class docblock.
			if ($retired !== null) {
				$this->calendar->onRemoved($retired);
				// The retired request is closed now, so any decision it was still
				// waiting on is moot — a withdrawal request against it above all,
				// which would otherwise sit in the manager's notifications offering
				// to withdraw leave that no longer exists.
				$this->notifications->dismiss($retired);
				// retireSuperseded() cancels the original with a direct write, so it
				// never passes through transitionToCancelled() where the replacement
				// would normally be released. Whoever covered the *old* version and is
				// not covering the new one has to be told, or they go on believing they
				// are on the hook. Staying silent when the person is unchanged is
				// deliberate: they still cover, and "no longer covering" immediately
				// followed by "you are covering" is noise, not information.
				$previous = $retired->getReplacementUid();
				if ($previous !== null && $previous !== '' && $previous !== $request->getReplacementUid()) {
					$this->notifications->notifyReplacementCancelled($retired);
				}
			}
			$this->applyCalendar($request);
			// Clear the now-stale "needs a decision" notifications other deciders
			// (e.g. the rest of the HR group) still have, then notify the outcome.
			$this->notifications->dismiss($request);
			$this->notifications->notifyDecision($request, true);
			$this->notifications->notifyReplacementAssigned($request);
			$this->activity->publish(ActivityPublisher::SUBJECT_APPROVED, $this->activityParams($request), [$request->getEmployeeUid(), $actorUid], $request);
			$this->audit('request_approved', $request, ['actor' => $actorUid, 'detail' => $comment]);
			return $request;
		}

		if ($status === LeaveRequest::STATUS_WITHDRAWAL_PENDING) {
			// Approving a withdrawal cancels the leave.
			return $this->transitionToCancelled($actorUid, $request, 'withdrawal_approved');
		}
		throw new ConflictException('This request cannot be approved in its current state.');
	}

	public function reject(string $actorUid, int $id, string $comment): LeaveRequest {
		if (trim($comment) === '') {
			throw new ValidationException('A comment is required when rejecting.');
		}
		if (mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
			throw new ValidationException('Comment is too long.');
		}
		$request = $this->get($actorUid, $id);
		if (!$this->permission->canDecide($actorUid, $request)) {
			throw new ForbiddenException('Not allowed to decide this request');
		}
		$status = $request->getStatus();

		if (in_array($status, [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_ESCALATED], true)) {
			$request = $this->atomic(function () use ($actorUid, $request, $comment): LeaveRequest {
				$now = $this->clock->now();
				$request->setStatus(LeaveRequest::STATUS_REJECTED);
				$request->setDecidedBy($actorUid);
				$request->setDecidedAt($now);
				$request->setDecisionComment($comment);
				$request->setUpdatedAt($now);
				return $this->requestMapper->update($request);
			}, $this->db);
			$this->notifications->dismiss($request);
			$this->notifications->notifyDecision($request, false);
			$this->activity->publish(ActivityPublisher::SUBJECT_REJECTED, $this->activityParams($request), [$request->getEmployeeUid(), $actorUid], $request);
			$this->audit('request_rejected', $request, ['actor' => $actorUid, 'detail' => $comment]);
			return $request;
		}

		if ($status === LeaveRequest::STATUS_WITHDRAWAL_PENDING) {
			// Rejecting a withdrawal returns the leave to approved. Keep the original
			// approval comment intact and record the refusal as a comment instead —
			// the status change and that comment are one unit, or the employee could
			// see their leave reinstated with no stated reason.
			$request = $this->atomic(function () use ($actorUid, $request, $comment): LeaveRequest {
				$request->setStatus(LeaveRequest::STATUS_APPROVED);
				$request->setUpdatedAt($this->clock->now());
				$request = $this->requestMapper->update($request);
				$this->recordSystemComment($actorUid, $request->getId(), 'Withdrawal declined: ' . $comment);
				return $request;
			}, $this->db);
			$this->notifications->dismiss($request);
			// A declined withdrawal is not an approval — tell the employee their
			// leave stands, not "your leave was approved, enjoy!".
			$this->notifications->notifyWithdrawalRejected($request, $comment, $actorUid);
			$this->activity->publish(ActivityPublisher::SUBJECT_APPROVED, $this->activityParams($request), [$request->getEmployeeUid(), $actorUid], $request);
			$this->audit('withdrawal_rejected', $request, ['actor' => $actorUid, 'detail' => $comment]);
			return $request;
		}
		throw new ConflictException('This request cannot be rejected in its current state.');
	}

	/**
	 * Mark a pending request as escalated to HR (used by the EscalationJob, §5.4).
	 */
	public function escalate(LeaveRequest $request): void {
		if ($request->getStatus() !== LeaveRequest::STATUS_PENDING) {
			return;
		}
		$request->setStatus(LeaveRequest::STATUS_ESCALATED);
		$request->setEscalated(true);
		$request->setUpdatedAt($this->clock->now());
		$this->requestMapper->update($request);
		$hrUids = $this->permission->getHrUids();
		$this->notifications->notifyEscalation($request, $hrUids);
		$this->activity->publish(ActivityPublisher::SUBJECT_ESCALATED, $this->activityParams($request), [$request->getEmployeeUid(), ...$hrUids], $request);
		$this->audit('request_escalated', $request, ['actor' => 'system']);
	}

	// ------------------------------------------------------------- comments ----

	public function addComment(string $actorUid, int $id, string $body): RequestComment {
		$request = $this->get($actorUid, $id);
		if (trim($body) === '') {
			throw new ValidationException('Comment cannot be empty.');
		}
		if (mb_strlen($body) > self::MAX_COMMENT_LENGTH) {
			throw new ValidationException('Comment is too long.');
		}
		$comment = new RequestComment();
		$comment->setRequestId($request->getId());
		$comment->setAuthorUid($actorUid);
		$comment->setBody($body);
		$comment->setCreatedAt($this->clock->now());
		$comment = $this->commentMapper->insert($comment);
		$this->audit('comment_added', $request, ['actor' => $actorUid, 'detail' => $body]);
		$this->notifications->notifyComment($request, $actorUid, $body, $this->commentRecipients($request));
		return $comment;
	}

	/**
	 * Who hears about a comment: the employee and their line manager, plus HR once
	 * the request has been through them, so a question HR asked does not sit
	 * unanswered. The author is filtered out by the notification service.
	 *
	 * @return string[]
	 */
	private function commentRecipients(LeaveRequest $request): array {
		$recipients = [$request->getEmployeeUid(), (string)$request->getManagerUid()];
		if ($request->getEscalated()) {
			$recipients = [...$recipients, ...$this->permission->getHrUids()];
		}
		return array_values(array_unique(array_filter($recipients)));
	}

	// --------------------------------------------------------------- helpers ----

	/**
	 * Record an important action: (1) a structured line in the Nextcloud server log
	 * (always-on, tagged app=absence) and (2) an immutable entry in the request's
	 * own history timeline shown to the employee, manager and HR (§15.1).
	 *
	 * @param array<string,mixed> $extra `actor` and optional `detail` are consumed;
	 *                                   everything else is added to the log context.
	 */
	private function audit(string $action, LeaveRequest $request, array $extra = []): void {
		$actor = (string)($extra['actor'] ?? 'system');
		$detail = isset($extra['detail']) && $extra['detail'] !== null && $extra['detail'] !== ''
			? (string)$extra['detail']
			: null;

		$this->logger->info('Absence action: ' . $action, array_merge([
			'app' => 'absence',
			'action' => $action,
			'requestId' => $request->getId(),
			'employee' => $request->getEmployeeUid(),
			'managerUid' => $request->getManagerUid(),
			'typeId' => $request->getTypeId(),
			'startDate' => $request->getStartDate(),
			'endDate' => $request->getEndDate(),
			'workingDays' => $request->getWorkingDays(),
			'status' => $request->getStatus(),
		], $extra));

		try {
			$event = new RequestEvent();
			$event->setRequestId((int)$request->getId());
			$event->setActorUid($actor);
			$event->setEventType($action);
			$event->setDetail($detail);
			$event->setCreatedAt($this->clock->now());
			$this->eventMapper->insert($event);
		} catch (\Throwable $e) {
			// History is best-effort: never let it break the workflow.
			$this->logger->warning('Absence: could not record history event', ['exception' => $e]);
		}
	}

	private function recordSystemComment(string $authorUid, int $requestId, string $body): void {
		$comment = new RequestComment();
		$comment->setRequestId($requestId);
		$comment->setAuthorUid($authorUid);
		$comment->setBody($body);
		$comment->setCreatedAt($this->clock->now());
		$this->commentMapper->insert($comment);
	}

	/**
	 * Cancel the request this one supersedes (§5.3). Database only: the caller
	 * removes the retired request's calendar entry once the transaction commits,
	 * so a rollback cannot leave the calendar out of step with the database.
	 *
	 * @return ?LeaveRequest the retired request, or null if there was nothing to retire
	 */
	private function retireSuperseded(LeaveRequest $request): ?LeaveRequest {
		if ($request->getSupersedesId() === null) {
			return null;
		}
		try {
			$original = $this->requestMapper->find($request->getSupersedesId());
		} catch (DoesNotExistException) {
			return null;
		}
		// WITHDRAWAL_PENDING counts as still in force, not just APPROVED: it is approved
		// leave awaiting a decision on withdrawing it, so it still occupies the dates and
		// still counts against the balance. Leaving it standing here is what let an
		// approved edit and its original both be counted. cancel() now refuses to start
		// a withdrawal while an edit is pending, so this pair can no longer be created —
		// but rows that predate that guard still have to retire cleanly.
		if (!in_array($original->getStatus(), [
			LeaveRequest::STATUS_APPROVED,
			LeaveRequest::STATUS_WITHDRAWAL_PENDING,
		], true)) {
			return null;
		}
		$now = $this->clock->now();
		$original->setStatus(LeaveRequest::STATUS_CANCELLED);
		$original->setDecidedAt($now);
		$original->setUpdatedAt($now);
		return $this->requestMapper->update($original);
	}

	private function applyCalendar(LeaveRequest $request): void {
		try {
			$uri = $this->calendar->onApproved($request);
			if ($uri !== null) {
				$request->setCalendarEventUri($uri);
				$this->requestMapper->update($request);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Absence: applyCalendar failed', ['exception' => $e]);
		}
	}

	private function resolveType(int $typeId): LeaveType {
		try {
			$type = $this->leaveTypeMapper->find($typeId);
		} catch (DoesNotExistException) {
			throw new ValidationException('Unknown leave type.');
		}
		if (!$type->getEnabled()) {
			throw new ValidationException('This leave type is disabled.');
		}
		return $type;
	}

	/**
	 * Guard the employee edit paths against reclassifying a request into a leave type
	 * that only HR may record (e.g. sick leave). Without this an employee could edit
	 * their own request to an HR-only or non-balance-counting type, mirroring the same
	 * check {@see create()} applies on submission (§5.6).
	 *
	 * @throws ForbiddenException
	 */
	private function assertSelfRequestable(LeaveType $type): void {
		if (!$type->getEmployeeRequestable()) {
			throw new ForbiddenException('This leave type is recorded by HR, not self-requested.');
		}
	}

	/** Whether the request's leave type is HR-recorded (not self-requestable, §5.6). */
	private function isHrRecordedType(LeaveRequest $request): bool {
		try {
			return !$this->leaveTypeMapper->find($request->getTypeId())->getEmployeeRequestable();
		} catch (DoesNotExistException) {
			return false;
		}
	}

	private function normaliseDate(string $date): string {
		$dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if ($dt === false) {
			throw new ValidationException('Invalid date: ' . $date);
		}
		return $dt->format('Y-m-d');
	}

	/**
	 * The employee enters the working-day count manually (§7). Validate it is a
	 * positive, sane number (allowing halves for a future half-day feature).
	 *
	 * @throws ValidationException
	 */
	private function normaliseWorkingDays(mixed $value): float {
		if ($value === null || $value === '' || !is_numeric($value)) {
			throw new ValidationException('Please enter the number of working days.');
		}
		$days = round((float)$value, 1);
		if ($days <= 0) {
			throw new ValidationException('The number of working days must be greater than zero.');
		}
		if ($days > 366) {
			throw new ValidationException('That is too many working days for a single request.');
		}
		return $days;
	}

	private function validateRange(string $actorUid, string $start, string $end, LeaveType $type, string $reason, string $note): void {
		if ($end < $start) {
			throw new ValidationException('The end date must be on or after the start date.');
		}
		// the employee's own today, not UTC's — see ClockService
		$today = $this->clock->userToday();
		if ($end < $today && !$this->permission->isHr($actorUid)) {
			throw new ValidationException('You cannot request leave entirely in the past.');
		}
		if ($type->getRequiresNote() && trim($reason) === '' && trim($note) === '') {
			throw new ValidationException('This leave type requires a note.');
		}
		if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
			throw new ValidationException('The reason is too long.');
		}
		if (mb_strlen($note) > self::MAX_REASON_LENGTH) {
			throw new ValidationException('The note is too long.');
		}
	}

	/**
	 * Only one edit may be in flight per approved request: a second one would be
	 * excluded from the overlap check as part of the supersedes chain, and once the
	 * first edit is approved (retiring the original) nothing would retire the other —
	 * two overlapping approved requests, both counted against the balance (§5.3).
	 *
	 * @throws ConflictException
	 */
	private function assertNoPendingEdit(LeaveRequest $original): void {
		foreach ($this->requestMapper->findBySupersedesId($original->getId()) as $sibling) {
			if (!in_array($sibling->getStatus(), LeaveRequest::TERMINAL_STATUSES, true)) {
				throw new ConflictException('There is already a pending edit of this leave. Wait for a decision on it, or cancel it first.');
			}
		}
	}

	/**
	 * @param int[] $excludeIds
	 */
	private function assertNoOverlap(string $employeeUid, string $start, string $end, array $excludeIds = []): void {
		if ($this->requestMapper->findOverlapping($employeeUid, $start, $end, $excludeIds) !== []) {
			throw new ConflictException('You already have a leave request overlapping these dates.');
		}
	}

	/**
	 * The full supersedes-chain around a request (itself, the request it supersedes,
	 * and any pending edits that supersede it) — excluded from overlap checks so an
	 * approved original and its in-flight edit don't flag each other (§5.3).
	 *
	 * @return list<int>
	 */
	private function chainExcludeIds(LeaveRequest $request): array {
		$ids = [];
		$id = $request->getId();
		if ($id !== null) {
			$ids[] = $id;
		}
		$supersedesId = $request->getSupersedesId();
		if ($supersedesId !== null) {
			$ids[] = $supersedesId;
		}
		foreach ($this->requestMapper->findBySupersedesId($request->getId()) as $child) {
			$childId = $child->getId();
			if ($childId !== null) {
				$ids[] = $childId;
			}
		}
		return $ids;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function activityParams(LeaveRequest $request): array {
		return [
			'requestId' => $request->getId(),
			'employee' => $request->getEmployeeUid(),
			'start' => $request->getStartDate(),
			'end' => $request->getEndDate(),
		];
	}
}
