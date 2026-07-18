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
use Psr\Log\LoggerInterface;

/**
 * The workflow orchestrator: enforces the request state machine (§4) and the
 * apply / review / edit / withdraw / escalate flows (§5), coordinating balances,
 * calendar, notifications and activity.
 */
class RequestService {
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
		private CoverageService $coverage,
		private CalendarService $calendar,
		private NotificationService $notifications,
		private ActivityPublisher $activity,
		private ConfigService $config,
		private \OCP\IUserManager $userManager,
		private LoggerInterface $logger,
	) {
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
		if (!$this->userManager->userExists($replacementUid)) {
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
		$detail = $this->withReplacementName($detail, $request);
		if ($detail['canDecide']) {
			$detail['coverage'] = $this->coverage->getRequestCoverage($request, $actorUid);
		}
		return $detail;
	}

	/**
	 * Serialize a list of requests, resolving the replacement display name so
	 * the client can show and reuse it (e.g. prefill a likely replacement).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function listSerialized(string $actorUid, array $filters, ?int $limit, ?int $offset): array {
		return array_map(
			fn (LeaveRequest $r) => $this->withReplacementName($r->jsonSerialize(), $r),
			$this->list($actorUid, $filters, $limit, $offset),
		);
	}

	/**
	 * Add the replacement's display name to a serialized request, falling back
	 * to the uid when the user no longer exists.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function withReplacementName(array $data, LeaveRequest $request): array {
		if ($request->getReplacementUid() !== null) {
			$user = $this->userManager->get($request->getReplacementUid());
			$data['replacementName'] = $user !== null ? $user->getDisplayName() : $request->getReplacementUid();
		}
		return $data;
	}

	/**
	 * List requests scoped by role (own / reports / all for HR).
	 *
	 * @param array<string,mixed> $filters
	 * @return LeaveRequest[]
	 */
	public function list(string $actorUid, array $filters, ?int $limit, ?int $offset): array {
		$scope = (string)($filters['scope'] ?? 'mine');
		$query = array_intersect_key($filters, array_flip(['status', 'typeId', 'from', 'to']));

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
	 * @param array{typeId:int,startDate:string,endDate:string,reason?:?string,attachmentNote?:?string,employeeUid?:?string} $data
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
		// Some types (e.g. sick leave) are recorded by HR, not self-requested (§5.6).
		if (!$type->getEmployeeRequestable() && !$isHr) {
			throw new ForbiddenException('This leave type is recorded by HR, not self-requested.');
		}

		$start = $this->normaliseDate((string)($data['startDate'] ?? ''));
		$end = $this->normaliseDate((string)($data['endDate'] ?? ''));
		$this->validateRange($actorUid, $start, $end, $type, (string)($data['reason'] ?? ''), (string)($data['attachmentNote'] ?? ''));
		$this->assertNoOverlap($employeeUid, $start, $end);
		$replacementUid = $this->resolveReplacement($employeeUid, $type, $data['replacementUid'] ?? null);

		// The employee enters the number of working days; the manager verifies it (§7).
		$workingDays = $this->normaliseWorkingDays($data['workingDays'] ?? null);

		$managerUid = $this->managerResolver->getManagerUid($employeeUid);
		$now = new \DateTime();

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
		$recordedDirectly = !$type->getEmployeeRequestable() || !$type->getRequiresApproval() || ($onBehalf && $isHr);
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

		$request = $this->requestMapper->insert($request);

		// Side effects by resulting status.
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
		$this->audit('request_created', $request, ['actor' => $actorUid, 'detail' => $this->createdDetail($request, $type, $onBehalf)]);
		return $request;
	}

	private function createdDetail(LeaveRequest $request, LeaveType $type, bool $onBehalf): ?string {
		if (!$type->getEmployeeRequestable()) {
			return 'Recorded by HR';
		}
		if ($onBehalf) {
			return 'Recorded by HR on behalf';
		}
		return match ($request->getStatus()) {
			LeaveRequest::STATUS_APPROVED => 'Automatically approved',
			LeaveRequest::STATUS_ESCALATED => 'No line manager — routed to HR',
			default => null,
		};
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

		// HR override: edit in place on any status, keeping calendar in sync.
		if ($isHr && $actorUid !== $request->getEmployeeUid()) {
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
		$this->assertNoOverlap($request->getEmployeeUid(), $start, $end, $this->chainExcludeIds($request));

		$replacementUid = $this->resolveReplacement($request->getEmployeeUid(), $type, $data['replacementUid'] ?? $request->getReplacementUid());

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
		$request->setUpdatedAt(new \DateTime());
		$request = $this->requestMapper->update($request);

		// Re-notify the decider that the request changed.
		if ($request->getStatus() === LeaveRequest::STATUS_ESCALATED) {
			$this->notifications->notifyEscalation($request, $this->permission->getHrUids());
		} elseif ($request->getManagerUid() !== null) {
			$this->notifications->notifyNewRequest($request, $request->getManagerUid());
		}
		$this->audit('request_updated', $request, ['actor' => $actorUid, 'detail' => 'Changed to ' . $request->getStartDate() . ' – ' . $request->getEndDate()]);
		return $request;
	}

	private function createSuperseding(string $actorUid, LeaveRequest $original, array $data): LeaveRequest {
		$type = $this->resolveType((int)($data['typeId'] ?? $original->getTypeId()));
		$this->assertSelfRequestable($type);
		$start = $this->normaliseDate((string)($data['startDate'] ?? $original->getStartDate()));
		$end = $this->normaliseDate((string)($data['endDate'] ?? $original->getEndDate()));
		$this->validateRange($actorUid, $start, $end, $type, (string)($data['reason'] ?? ''), (string)($data['attachmentNote'] ?? ''));
		// The original still occupies its dates; exclude it and its chain from the check.
		$this->assertNoOverlap($actorUid, $start, $end, $this->chainExcludeIds($original));
		$replacementUid = $this->resolveReplacement($actorUid, $type, $data['replacementUid'] ?? $original->getReplacementUid());

		$now = new \DateTime();
		$managerUid = $this->managerResolver->getManagerUid($actorUid);
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
		$new = $this->requestMapper->insert($new);

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
			$type = $this->leaveTypeMapper->find($request->getTypeId());
			$request->setReplacementUid($this->resolveReplacement($request->getEmployeeUid(), $type, $data['replacementUid']));
		}
		// HR may correct the working-day count (§5.5); otherwise it is kept as entered.
		if (array_key_exists('workingDays', $data) && $data['workingDays'] !== null) {
			$request->setWorkingDays($this->normaliseWorkingDays($data['workingDays']));
		}
		if ($request->getEndDate() < $request->getStartDate()) {
			throw new ValidationException('The end date must be on or after the start date.');
		}
		$this->assertNoOverlap($request->getEmployeeUid(), $request->getStartDate(), $request->getEndDate(), $this->chainExcludeIds($request));
		$request->setUpdatedAt(new \DateTime());
		$request = $this->requestMapper->update($request);

		if ($wasApproved) {
			// Rebuild the calendar entry for the new dates.
			$this->calendar->onRemoved($request);
			$this->applyCalendar($request);
		}
		$this->activity->publish(ActivityPublisher::SUBJECT_CREATED, $this->activityParams($request), [$request->getEmployeeUid()], $request);
		$this->audit('request_hr_edited', $request, ['actor' => $actorUid, 'detail' => 'HR adjusted to ' . $request->getStartDate() . ' – ' . $request->getEndDate() . ' (' . $request->getWorkingDays() . ' days)']);
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
		$isHrOverride = $this->permission->isHr($actorUid) && $actorUid !== $request->getEmployeeUid();

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
			// Employee: approved leave requires a withdrawal approval step.
			$request->setStatus(LeaveRequest::STATUS_WITHDRAWAL_PENDING);
			$request->setUpdatedAt(new \DateTime());
			$request = $this->requestMapper->update($request);
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
		// If an already-approved leave is being cancelled, the replacement no longer covers.
		$wasApproved = $request->getStatus() === LeaveRequest::STATUS_APPROVED;
		$this->calendar->onRemoved($request);
		$request->setStatus(LeaveRequest::STATUS_CANCELLED);
		$request->setDecidedBy($actorUid);
		$request->setDecidedAt(new \DateTime());
		$request->setUpdatedAt(new \DateTime());
		$request = $this->requestMapper->update($request);
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
			$request->setStatus(LeaveRequest::STATUS_APPROVED);
			$request->setDecidedBy($actorUid);
			$request->setDecidedAt(new \DateTime());
			$request->setDecisionComment($comment);
			$request->setUpdatedAt(new \DateTime());
			$request = $this->requestMapper->update($request);

			// If this supersedes an approved request, retire the original now (§5.3).
			$this->retireSuperseded($request);
			$this->applyCalendar($request);
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
			$request->setStatus(LeaveRequest::STATUS_REJECTED);
			$request->setDecidedBy($actorUid);
			$request->setDecidedAt(new \DateTime());
			$request->setDecisionComment($comment);
			$request->setUpdatedAt(new \DateTime());
			$request = $this->requestMapper->update($request);
			$this->notifications->notifyDecision($request, false);
			$this->activity->publish(ActivityPublisher::SUBJECT_REJECTED, $this->activityParams($request), [$request->getEmployeeUid(), $actorUid], $request);
			$this->audit('request_rejected', $request, ['actor' => $actorUid, 'detail' => $comment]);
			return $request;
		}

		if ($status === LeaveRequest::STATUS_WITHDRAWAL_PENDING) {
			// Rejecting a withdrawal returns the leave to approved. Keep the original
			// approval comment intact and record the refusal as a comment instead.
			$request->setStatus(LeaveRequest::STATUS_APPROVED);
			$request->setUpdatedAt(new \DateTime());
			$request = $this->requestMapper->update($request);
			$this->recordSystemComment($actorUid, $request->getId(), 'Withdrawal declined: ' . $comment);
			$this->notifications->notifyDecision($request, true);
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
		$request->setUpdatedAt(new \DateTime());
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
		$comment->setCreatedAt(new \DateTime());
		$comment = $this->commentMapper->insert($comment);
		$this->audit('comment_added', $request, ['actor' => $actorUid, 'detail' => $body]);
		return $comment;
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
			$event->setCreatedAt(new \DateTime());
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
		$comment->setCreatedAt(new \DateTime());
		$this->commentMapper->insert($comment);
	}

	private function retireSuperseded(LeaveRequest $request): void {
		if ($request->getSupersedesId() === null) {
			return;
		}
		try {
			$original = $this->requestMapper->find($request->getSupersedesId());
		} catch (DoesNotExistException) {
			return;
		}
		if ($original->getStatus() !== LeaveRequest::STATUS_APPROVED) {
			return;
		}
		$this->calendar->onRemoved($original);
		$original->setStatus(LeaveRequest::STATUS_CANCELLED);
		$original->setDecidedAt(new \DateTime());
		$original->setUpdatedAt(new \DateTime());
		$this->requestMapper->update($original);
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
		$today = date('Y-m-d');
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
	 * @return int[]
	 */
	private function chainExcludeIds(LeaveRequest $request): array {
		$ids = [$request->getId()];
		if ($request->getSupersedesId() !== null) {
			$ids[] = $request->getSupersedesId();
		}
		foreach ($this->requestMapper->findBySupersedesId($request->getId()) as $child) {
			$ids[] = $child->getId();
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
