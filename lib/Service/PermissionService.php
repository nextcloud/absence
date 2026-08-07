<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveTypeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Central authorization for the app (spec §17). Every controller consults this
 * rather than re-deriving role checks.
 */
class PermissionService {
	public function __construct(
		private IGroupManager $groupManager,
		private ManagerResolver $managerResolver,
		private ConfigService $config,
		private LeaveTypeMapper $leaveTypeMapper,
		private LoggerInterface $logger,
	) {
	}

	public function isHr(string $uid): bool {
		return $this->groupManager->isInGroup($uid, $this->config->getHrGroup());
	}

	public function isManagerOf(string $actorUid, string $employeeUid): bool {
		return $this->managerResolver->isManagerOf($actorUid, $employeeUid);
	}

	/** Anyone who manages at least one report. */
	public function isManager(string $uid): bool {
		return $this->managerResolver->getDirectReports($uid) !== [];
	}

	/**
	 * May the actor view this request (own, is manager of employee, or HR).
	 */
	public function canView(string $actorUid, LeaveRequest $request): bool {
		return $actorUid === $request->getEmployeeUid()
			|| $this->isHr($actorUid)
			|| $this->isManagerOf($actorUid, $request->getEmployeeUid());
	}

	/**
	 * May the actor approve/reject this request. The employee can never decide
	 * their own; the assigned manager can; HR can always (override, §5.5).
	 */
	public function canDecide(string $actorUid, LeaveRequest $request): bool {
		if ($actorUid === $request->getEmployeeUid()) {
			return false;
		}
		if ($this->isHr($actorUid)) {
			return true;
		}
		return $request->getManagerUid() === $actorUid
			|| $this->isManagerOf($actorUid, $request->getEmployeeUid());
	}

	/**
	 * May the actor edit/cancel this request. HR always can. The owner can too —
	 * *unless* it is an HR-recorded leave type (e.g. sick leave), which only HR may
	 * change (§5.6).
	 */
	public function canModify(string $actorUid, LeaveRequest $request): bool {
		if ($this->isHr($actorUid)) {
			return true;
		}
		if ($actorUid !== $request->getEmployeeUid()) {
			return false;
		}
		try {
			return $this->leaveTypeMapper->find($request->getTypeId())->getEmployeeRequestable();
		} catch (DoesNotExistException) {
			return true;
		}
	}

	/**
	 * May the actor read another employee's balance (self, manager, or HR).
	 */
	public function canViewBalanceOf(string $actorUid, string $employeeUid): bool {
		return $actorUid === $employeeUid
			|| $this->isHr($actorUid)
			|| $this->isManagerOf($actorUid, $employeeUid);
	}

	/**
	 * @throws \OCA\Absence\Exception\ForbiddenException
	 */
	public function assertHr(string $uid): void {
		if (!$this->isHr($uid)) {
			throw new \OCA\Absence\Exception\ForbiddenException('HR role required');
		}
	}

	/**
	 * The uids of all HR-group members (recipients for escalation, §5.4).
	 *
	 * An empty result is a misconfiguration, not a normal state: escalation is the
	 * app's last resort for a request nobody else can decide, so with no HR member
	 * to notify those requests sit unanswered and invisible. Nothing downstream can
	 * tell "no HR group" apart from "HR group with no members", and neither can be
	 * fixed from here, so both are logged loudly for the admin.
	 *
	 * @return string[]
	 */
	public function getHrUids(): array {
		$groupId = $this->config->getHrGroup();
		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			$this->logger->error('Absence: the configured HR group does not exist — escalated leave requests will reach nobody', [
				'app' => 'absence',
				'hrGroup' => $groupId,
			]);
			return [];
		}
		$uids = array_map(static fn ($user) => $user->getUID(), $group->getUsers());
		if ($uids === []) {
			$this->logger->error('Absence: the configured HR group is empty — escalated leave requests will reach nobody', [
				'app' => 'absence',
				'hrGroup' => $groupId,
			]);
		}
		return $uids;
	}
}
