<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Exception\ValidationException;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Team coverage / who's-off computation and conflict detection (spec §8).
 */
class CoverageService {
	use DateRangeTrait;

	public const SCOPE_TEAM = 'team';
	public const SCOPE_COMPANY = 'company';
	/** The longest range the away check answers for: the longest request (§5.1). */
	private const MAX_AWAY_SPAN_DAYS = 366;

	/** The statuses that put somebody on the timeline: booked or still asked for */
	private const OFF_STATUSES = [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_ESCALATED, LeaveRequest::STATUS_WITHDRAWAL_PENDING];

	public function __construct(
		private LeaveRequestMapper $requestMapper,
		private LeaveTypeMapper $leaveTypeMapper,
		private ManagerResolver $managerResolver,
		private PermissionService $permission,
		private ConfigService $config,
		private EmployeeDirectory $employees,
		private IUserManager $userManager,
		private IL10N $l,
	) {
	}

	/**
	 * Resolve the set of employees an actor may see for a given scope. Nobody for
	 * an account that is neither an employee nor HR (§2.2).
	 *
	 * @return string[]
	 */
	public function resolveScopeUids(string $actorUid, string $scope): array {
		if (!$this->permission->isEmployeeOrHr($actorUid)) {
			return [];
		}
		if ($scope === self::SCOPE_COMPANY && $this->permission->isHr($actorUid)) {
			return $this->employees->listAll();
		}
		// Team scope: the actor's reports if they manage, otherwise their peers + self.
		$reports = $this->managerResolver->getDirectReports($actorUid);
		if ($reports !== []) {
			return array_values(array_unique([...$reports, $actorUid]));
		}
		return array_values(array_unique([...$this->managerResolver->getPeers($actorUid), $actorUid]));
	}

	/**
	 * Who's-off events + per-day concurrency for a set of employees in a range.
	 *
	 * The leave *type* of another employee is only revealed when the admin set the
	 * shared-calendar visibility to "reveal", or when the viewer is somebody who may
	 * open that request and read the type off it anyway — its owner, their line
	 * manager, or HR ({@see PermissionService::canView()}). Under the default
	 * "neutral" policy everyone else sees that a colleague is absent but not the
	 * category, mirroring {@see CalendarService::sharedTitle}. When no viewer is
	 * given, types are neutralised for everyone (fail-closed).
	 *
	 * The policy exists to stop a *colleague's* sick leave becoming visible to the
	 * team (§8); withholding the type from HR, who record sick leave in the first
	 * place, protects nothing and actively misinforms — the client has no type to
	 * label the absence with and has to fall back to a generic marker, so an HR
	 * timeline of sick colleagues used to read as a row of holidays.
	 *
	 * @param string[] $employeeUids
	 * @return array{events:list<array<string,mixed>>,byDate:array<string,int>,maxConcurrent:int,threshold:int,conflict:bool}
	 */
	public function getCoverage(array $employeeUids, string $from, string $to, ?int $excludeRequestId = null, ?string $viewerUid = null): array {
		[$from, $to] = $this->assertValidRange($from, $to);
		$revealTypes = $this->config->getSharedCalendarVisibility() === ConfigService::VISIBILITY_REVEAL;
		$requests = $this->requestMapper->findForEmployeesInRange($employeeUids, $from, $to, self::OFF_STATUSES);

		// Resolve the viewer's reach once rather than asking canView() per request: a
		// company-wide month can hold hundreds of rows, and the answer depends only on
		// who is looking, not on which request.
		$isHrViewer = $viewerUid !== null && $this->permission->isHr($viewerUid);
		$viewerReports = (!$revealTypes && !$isHrViewer && $viewerUid !== null)
			? $this->managerResolver->getDirectReports($viewerUid)
			: [];
		// Confidential categories (§5.7) are neutral for everyone but HR — the
		// admin's "reveal" setting, self-view and the manager relationship all
		// stop mattering for these.
		$hrOnlyIds = $this->leaveTypeMapper->hrOnlyTypeIds();

		$events = [];
		$byDate = [];
		foreach ($requests as $request) {
			if ($excludeRequestId !== null && $request->getId() === $excludeRequestId) {
				continue;
			}
			$employeeUid = $request->getEmployeeUid();
			$maySeeType = in_array($request->getTypeId(), $hrOnlyIds, true)
				? $isHrViewer
				: ($revealTypes
					|| $isHrViewer
					|| ($viewerUid !== null && $employeeUid === $viewerUid)
					|| in_array($employeeUid, $viewerReports, true));
			$events[] = [
				'requestId' => $request->getId(),
				'employeeUid' => $employeeUid,
				'displayName' => $this->displayName($employeeUid),
				'typeId' => $maySeeType ? $request->getTypeId() : null,
				'status' => $request->getStatus(),
				'start' => $request->getStartDate(),
				'end' => $request->getEndDate(),
			];
			// Count only approved requests toward concurrency (planning against confirmed).
			if ($request->getStatus() === LeaveRequest::STATUS_APPROVED) {
				$this->accumulateDays($byDate, $request->getStartDate(), $request->getEndDate(), $from, $to);
			}
		}

		$maxConcurrent = $byDate === [] ? 0 : max($byDate);
		$threshold = $this->config->getMaxConcurrentAbsences();

		return [
			'events' => $events,
			'byDate' => $byDate,
			'maxConcurrent' => $maxConcurrent,
			'threshold' => $threshold,
			'conflict' => $threshold > 0 && $maxConcurrent >= $threshold,
		];
	}

	/**
	 * Coverage summary for reviewing a specific request: counts *other* team members
	 * off during the request's dates and flags a conflict if approving would meet the
	 * configured threshold.
	 *
	 * @return array<string,mixed>
	 */
	public function getRequestCoverage(LeaveRequest $request, ?string $viewerUid = null): array {
		$team = $this->teamOf($request->getEmployeeUid(), $request->getManagerUid());
		$others = array_values(array_filter($team, static fn (string $uid): bool => $uid !== $request->getEmployeeUid()));
		$coverage = $this->getCoverage($others, $request->getStartDate(), $request->getEndDate(), $request->getId(), $viewerUid);

		$threshold = $this->config->getMaxConcurrentAbsences();
		// Approving this request would add one concurrent absence on top of others.
		$projectedPeak = $coverage['maxConcurrent'] + 1;
		$coverage['projectedPeak'] = $projectedPeak;
		$coverage['conflict'] = $threshold > 0 && $projectedPeak >= $threshold;
		return $coverage;
	}

	/**
	 * For each request of a decision queue, the other members of its team who are
	 * off on at least one of its days: the people {@see getRequestCoverage()} lists,
	 * for the whole queue in one query. Names only, without the leave type, so a
	 * confidential absence (§5.7) shows as nothing more than somebody being away.
	 *
	 * @param LeaveRequest[] $requests
	 * @return array<int,list<string>> display names, one per person, keyed by request id
	 */
	public function othersOffFor(array $requests): array {
		$teams = [];
		$from = null;
		$to = null;
		foreach ($requests as $request) {
			$employeeUid = $request->getEmployeeUid();
			$teams[$request->getId()] = array_values(array_filter(
				$this->teamOf($employeeUid, $request->getManagerUid()),
				static fn (string $uid): bool => $uid !== $employeeUid,
			));
			$from = $from === null ? $request->getStartDate() : min($from, $request->getStartDate());
			$to = $to === null ? $request->getEndDate() : max($to, $request->getEndDate());
		}
		if ($from === null || $to === null) {
			return [];
		}
		$off = $this->requestMapper->findForEmployeesInRange(
			array_values(array_unique(array_merge(...array_values($teams)))),
			$from,
			$to,
			self::OFF_STATUSES,
		);

		$result = [];
		foreach ($requests as $request) {
			$names = [];
			foreach ($off as $other) {
				$uid = $other->getEmployeeUid();
				if (!in_array($uid, $teams[$request->getId()], true)
					|| $other->getStartDate() > $request->getEndDate()
					|| $other->getEndDate() < $request->getStartDate()) {
					continue;
				}
				$names[$uid] ??= $this->displayName($uid);
			}
			$result[$request->getId()] = array_values($names);
		}
		return $result;
	}

	/**
	 * The colleagues the replacement picker offers before anything is typed
	 * (§5.1): who shares the person's manager, the manager, and the person's
	 * direct reports. Employees only, never the person themselves. Anybody may
	 * ask for their own, HR for anybody's.
	 *
	 * @return list<array{uid: string, displayName: string}>
	 * @throws ForbiddenException
	 */
	public function getTeammates(string $actorUid, string $uid): array {
		if ($uid !== $actorUid) {
			$this->permission->assertHr($actorUid);
		} elseif (!$this->permission->isEmployeeOrHr($actorUid)) {
			throw new ForbiddenException($this->l->t('Only employees and HR can see this.'));
		}
		return array_map(fn (string $other): array => ['uid' => $other, 'displayName' => $this->displayName($other)], $this->teammateUids($uid));
	}

	/**
	 * Who shares the person's manager, the manager, and the person's direct
	 * reports: employees only, never the person themselves.
	 *
	 * @return list<string>
	 */
	private function teammateUids(string $uid): array {
		$managerUid = $this->managerResolver->getManagerUid($uid);
		$uids = array_unique([
			...$this->managerResolver->getPeers($uid),
			...($managerUid !== null ? [$managerUid] : []),
			...$this->managerResolver->getDirectReports($uid),
		]);
		return $this->employees->filter(array_values(array_filter($uids, static fn (string $other): bool => $other !== $uid)));
	}

	/**
	 * Whether an employee has leave in force or asked for on any day of a
	 * range, for the warning under the replacement picker. Says nothing about
	 * the type or the days. Null, no answer, unless the caller may see that
	 * person's absences anyway: HR, their teammates, or everyone while the
	 * shared team calendar shows all absences.
	 *
	 * @throws ForbiddenException
	 * @throws ValidationException
	 */
	public function isAway(string $actorUid, string $uid, string $from, string $to): ?bool {
		if (!$this->permission->isEmployeeOrHr($actorUid)) {
			throw new ForbiddenException($this->l->t('Only employees and HR can see this.'));
		}
		if (!$this->employees->isEmployee($uid)) {
			throw new ValidationException($this->l->t('Unknown employee.'));
		}
		[$from, $to] = $this->assertValidRange($from, $to, self::MAX_AWAY_SPAN_DAYS);
		if (!$this->permission->isHr($actorUid) && !$this->config->isCalDavSharedEnabled() && !in_array($uid, $this->teammateUids($actorUid), true)) {
			return null;
		}
		return $this->requestMapper->findForEmployeesInRange([$uid], $from, $to, LeaveRequest::ACTIVE_STATUSES) !== [];
	}

	/**
	 * The team relevant to a request: the manager's direct reports, or (no manager)
	 * the employee's peers.
	 *
	 * @return string[]
	 */
	private function teamOf(string $employeeUid, ?string $managerUid): array {
		if ($managerUid !== null) {
			$reports = $this->managerResolver->getDirectReports($managerUid);
			if ($reports !== []) {
				return $reports;
			}
		}
		return array_values(array_unique([...$this->managerResolver->getPeers($employeeUid), $employeeUid]));
	}

	/**
	 * @param array<string,int> $byDate
	 */
	private function accumulateDays(array &$byDate, string $start, string $end, string $clampFrom, string $clampTo): void {
		$cursor = new \DateTimeImmutable(max($start, $clampFrom));
		$last = new \DateTimeImmutable(min($end, $clampTo));
		while ($cursor <= $last) {
			$key = $cursor->format('Y-m-d');
			$byDate[$key] = ($byDate[$key] ?? 0) + 1;
			$cursor = $cursor->modify('+1 day');
		}
	}

	private function displayName(string $uid): string {
		$user = $this->userManager->get($uid);
		return $user !== null ? $user->getDisplayName() : $uid;
	}
}
