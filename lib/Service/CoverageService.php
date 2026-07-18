<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCP\IUserManager;

/**
 * Team coverage / who's-off computation and conflict detection (spec §8).
 */
class CoverageService {
	use DateRangeTrait;

	public const SCOPE_TEAM = 'team';
	public const SCOPE_COMPANY = 'company';

	public function __construct(
		private LeaveRequestMapper $requestMapper,
		private ManagerResolver $managerResolver,
		private PermissionService $permission,
		private ConfigService $config,
		private IUserManager $userManager,
	) {
	}

	/**
	 * Resolve the set of employees an actor may see for a given scope.
	 *
	 * @return string[]
	 */
	public function resolveScopeUids(string $actorUid, string $scope): array {
		if ($scope === self::SCOPE_COMPANY && $this->permission->isHr($actorUid)) {
			$uids = [];
			$this->userManager->callForAllUsers(static function ($user) use (&$uids): void {
				$uids[] = $user->getUID();
			});
			return $uids;
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
	 * shared-calendar visibility to "reveal"; under the default "neutral" policy the
	 * viewer sees that a colleague is absent but not the category (e.g. sick leave),
	 * mirroring {@see CalendarService::sharedTitle}. The viewer always sees their own
	 * types. When no viewer is given, types are neutralised for everyone under the
	 * neutral policy (fail-closed).
	 *
	 * @param string[] $employeeUids
	 * @return array{events:list<array<string,mixed>>,byDate:array<string,int>,maxConcurrent:int,threshold:int,conflict:bool}
	 */
	public function getCoverage(array $employeeUids, string $from, string $to, ?int $excludeRequestId = null, ?string $viewerUid = null): array {
		[$from, $to] = $this->assertValidRange($from, $to);
		$revealTypes = $this->config->getSharedCalendarVisibility() === ConfigService::VISIBILITY_REVEAL;
		$statuses = [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_ESCALATED, LeaveRequest::STATUS_WITHDRAWAL_PENDING];
		$requests = $this->requestMapper->findForEmployeesInRange($employeeUids, $from, $to, $statuses);

		$events = [];
		$byDate = [];
		foreach ($requests as $request) {
			if ($excludeRequestId !== null && $request->getId() === $excludeRequestId) {
				continue;
			}
			$ownEvent = $viewerUid !== null && $request->getEmployeeUid() === $viewerUid;
			$events[] = [
				'requestId' => $request->getId(),
				'employeeUid' => $request->getEmployeeUid(),
				'displayName' => $this->displayName($request->getEmployeeUid()),
				'typeId' => ($revealTypes || $ownEvent) ? $request->getTypeId() : null,
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
