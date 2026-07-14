<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\LeaveRequestMapper;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Assembles the "who am I" payload the SPA needs on boot: role flags, region and
 * navigation badge counts.
 */
class SessionService {
	public function __construct(
		private IUserSession $userSession,
		private IUserManager $userManager,
		private PermissionService $permission,
		private ManagerResolver $managerResolver,
		private LeaveRequestMapper $requestMapper,
		private PersonalDefaultsService $personalDefaults,
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getSessionInfo(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return ['uid' => null];
		}
		$uid = $user->getUID();
		$isHr = $this->permission->isHr($uid);
		$reports = $this->managerResolver->getDirectReports($uid);
		$isManager = $reports !== [];
		$managerUid = $this->managerResolver->getManagerUid($uid);
		$defaults = $this->personalDefaults->resolve($uid);

		return [
			'uid' => $uid,
			'workWeekdays' => $defaults['workWeekdays'],
			'holidayCountry' => $defaults['holidayCountry'],
			'holidayRegion' => $defaults['holidayRegion'],
			'displayName' => $user->getDisplayName(),
			'isHr' => $isHr,
			'isManager' => $isManager,
			'managerUid' => $managerUid,
			'managerName' => $managerUid !== null ? $this->displayName($managerUid) : null,
			'pendingApprovals' => $isManager ? count($this->requestMapper->findPendingForManager($uid)) : 0,
			'escalatedCount' => $isHr ? count($this->requestMapper->findEscalated()) : 0,
		];
	}

	private function displayName(string $uid): string {
		$user = $this->userManager->get($uid);
		return $user !== null ? $user->getDisplayName() : $uid;
	}
}
