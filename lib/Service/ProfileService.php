<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\EmployeeChange;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Exception\NotFoundException;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Everything the profile page shows about someone (§15.2): who they report to,
 * their region code and its holidays, their contract, sick days and next leave.
 * The person, their manager and HR may read it; only HR sees the history of
 * other people's changes.
 */
class ProfileService {
	private const SICK_KEY = 'sick';
	private const UPCOMING_HOLIDAYS = 2;

	public function __construct(
		private IUserManager $userManager,
		private IUserConfig $userConfig,
		private IAppManager $appManager,
		private PermissionService $permission,
		private ManagerResolver $managers,
		private ConfigService $config,
		private HolidayService $holidays,
		private EmployeeService $employees,
		private LeaveRequestMapper $requests,
		private LeaveTypeMapper $types,
		private ClockService $clock,
		private IL10N $l,
	) {
	}

	/**
	 * An account that does not exist is refused like someone else's profile, so
	 * only HR, who may open them all, learns that there is none.
	 *
	 * @return array<string, mixed>
	 * @throws ForbiddenException|NotFoundException
	 */
	public function get(string $viewer, string $uid): array {
		if (!$this->permission->canViewBalanceOf($viewer, $uid)) {
			throw new ForbiddenException($this->l->t('You may not see this profile'));
		}
		$user = $this->userManager->get($uid);
		if ($user === null) {
			throw new NotFoundException($this->l->t('Employee not found'));
		}
		$isHr = $this->permission->isHr($viewer);
		$managerUid = $this->managers->getManagerUid($uid);
		$personal = $this->config->getPersonalConfig($uid);
		$code = ConfigService::regionCode($personal[ConfigLexicon::KEY_HOLIDAY_COUNTRY], $personal[ConfigLexicon::KEY_HOLIDAY_REGION]);
		$today = $this->clock->userToday();
		$year = (int)substr($today, 0, 4);

		$holidays = $this->holidays->forRegions($code === '' ? [] : [$code], sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year));
		$upcoming = array_values(array_filter($holidays, static fn ($h): bool => $h->getDate() >= $today));

		return [
			'uid' => $uid,
			'displayName' => $user->getDisplayName(),
			'self' => $viewer === $uid,
			'canEditContract' => $isHr,
			'manager' => $managerUid === null ? null : ['uid' => $managerUid, 'displayName' => $this->displayName($managerUid)],
			'timezone' => $this->userConfig->getValueString($uid, 'core', 'timezone'),
			'contacts' => $this->appManager->isEnabledForUser('contacts'),
			'regionCode' => $code,
			'holidays' => [
				'year' => $year,
				'count' => count($holidays),
				'upcoming' => array_map(static fn ($h): array => ['date' => $h->getDate(), 'label' => $h->getLabel()],
					array_slice($upcoming, 0, self::UPCOMING_HOLIDAYS)),
				'remaining' => count($upcoming),
			],
			'contract' => $this->employees->getContract($uid),
			...$this->leave($uid, $year, $today, $isHr),
			'history' => $isHr || $viewer === $uid ? $this->history($uid) : null,
		];
	}

	/**
	 * Sick days this year and the next approved leave. Absences of a
	 * confidential type (§5.7) count for HR only: for everyone else they are
	 * neither the next leave nor sick days, and there are no sick figures at
	 * all when sick leave itself is confidential.
	 *
	 * @return array{sick: ?array{year: int, days: float, times: int}, nextLeave: ?array<string, mixed>}
	 */
	private function leave(string $uid, int $year, string $today, bool $viewerIsHr): array {
		$types = [];
		$sickWithheld = false;
		foreach ($this->types->findAll() as $type) {
			if ($type->getHrOnly() && !$viewerIsHr) {
				$sickWithheld = $sickWithheld || $type->getKey() === self::SICK_KEY;
				continue;
			}
			$types[$type->getId()] = $type;
		}
		$sickDays = 0.0;
		$sickTimes = 0;
		$next = null;
		$approved = $this->requests->findForEmployeesInRange([$uid], sprintf('%04d-01-01', $year), '9999-12-31', [LeaveRequest::STATUS_APPROVED]);
		foreach ($approved as $request) {
			$type = $types[$request->getTypeId()] ?? null;
			if ($type === null) {
				continue;
			}
			if ($type->getKey() === self::SICK_KEY) {
				if ((int)substr($request->getStartDate(), 0, 4) === $year) {
					$sickDays += $request->getWorkingDays();
					$sickTimes++;
				}
			} elseif ($type->getCountsAgainstBalance() && $request->getEndDate() >= $today
				&& ($next === null || $request->getStartDate() < $next->getStartDate())) {
				$next = $request;
			}
		}
		return [
			'sick' => $sickWithheld ? null : ['year' => $year, 'days' => $sickDays, 'times' => $sickTimes],
			'nextLeave' => $next === null ? null : [
				'id' => $next->getId(),
				'startDate' => $next->getStartDate(),
				'endDate' => $next->getEndDate(),
				'workingDays' => $next->getWorkingDays(),
			],
		];
	}

	/**
	 * The history with names for its uids. A manager change lists the managers
	 * before and after as people; a deleted account among them has an empty uid.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function history(string $uid): array {
		$names = [];
		$name = function (string $uid) use (&$names): string {
			return $names[$uid] ??= $this->displayName($uid);
		};
		$people = fn (array $uids): array => array_map(fn (?string $uid): array => [
			'uid' => $uid ?? '',
			'displayName' => $uid === null ? $this->l->t('Deleted account') : $name($uid),
		], $uids);
		return array_map(static function (array $change) use ($name, $people): array {
			$actor = $change['actor'];
			$change['actorName'] = $actor === null ? null : $name($actor);
			if ($change['field'] === EmployeeChange::MANAGER) {
				$change['before'] = $people($change['before']);
				$change['after'] = $people($change['after']);
			}
			return $change;
		}, $this->employees->history($uid));
	}

	private function displayName(string $uid): string {
		return $this->userManager->get($uid)?->getDisplayName() ?? $this->l->t('Deleted account');
	}
}
