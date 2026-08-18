<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCP\Config\IUserConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Resolves the line-manager relationship from the user's `manager` account field
 * ({@see IUser::getManagerUids()}, populated from LDAP where configured), and the
 * inverse "direct reports" set. Results are cached per request (spec §2.1).
 *
 * The inverse direction is deliberately *not* answered by enumerating every
 * account: this resolver sits inside `canView`/`canDecide` and the session
 * bootstrap, so it runs on almost every request, and walking the whole user
 * backend there made every click O(headcount) on large LDAP instances. Instead:
 *
 *  - `isManagerOf()` reads the *employee's* own manager property — one user
 *    lookup, no directory access at all;
 *  - `getDirectReports()` reads the stored `manager` preference for all users
 *    in a single indexed query ({@see IUserConfig::getValuesByUsers()}; the
 *    server stores {@see IUser::getManagerUids()} as the `settings/manager`
 *    user preference) and resolves candidates from that in memory.
 */
class ManagerResolver {
	/**
	 * Where the server keeps IUser::getManagerUids(): the `manager` preference
	 * of the `settings` app, as a JSON list of uids (OC\User\User).
	 */
	private const MANAGER_PREF_APP = 'settings';
	private const MANAGER_PREF_KEY = 'manager';

	/** @var array<string,?string> uid => manager uid|null */
	private array $managerCache = [];
	/** @var array<string,string[]> manager uid => report uids, resolved lazily */
	private array $reportsCache = [];
	/** @var array<string,string[]>|null uid => configured manager uids (one query) */
	private ?array $configuredManagers = null;

	public function __construct(
		private IUserManager $userManager,
		private IUserConfig $userConfig,
		private EmployeeDirectory $employees,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The manager uid for an employee, or null when none is set / resolvable.
	 */
	public function getManagerUid(string $employeeUid): ?string {
		if (array_key_exists($employeeUid, $this->managerCache)) {
			return $this->managerCache[$employeeUid];
		}
		$manager = null;
		$user = $this->userManager->get($employeeUid);
		if ($user instanceof IUser) {
			$manager = $this->firstValidManager($employeeUid, $this->readManagerUids($user));
		}
		return $this->managerCache[$employeeUid] = $manager;
	}

	/**
	 * @return string[] the raw configured manager uids, [] on backend failure
	 */
	private function readManagerUids(IUser $user): array {
		try {
			return $user->getManagerUids();
		} catch (\Throwable $e) {
			$this->logger->debug('Absence: could not read manager for ' . $user->getUID(), ['exception' => $e]);
			return [];
		}
	}

	/**
	 * A user may have several configured managers; we use the first valid one.
	 *
	 * @param string[] $managerUids
	 */
	private function firstValidManager(string $employeeUid, array $managerUids): ?string {
		foreach ($managerUids as $uid) {
			$uid = trim((string)$uid);
			// A guest cannot be a line manager — they have no standing in the app,
			// so routing an approval to them would strand the request (§2.2).
			if ($uid !== '' && $uid !== $employeeUid && $this->employees->isEmployee($uid)) {
				return $uid;
			}
		}
		return null;
	}

	/**
	 * All employees whose manager is $managerUid.
	 *
	 * @return string[]
	 */
	public function getDirectReports(string $managerUid): array {
		if (isset($this->reportsCache[$managerUid])) {
			return $this->reportsCache[$managerUid];
		}
		$reports = [];
		foreach ($this->configuredManagers() as $uid => $managerUids) {
			// Cheap containment test first; the first-valid rule and the
			// employee test only run for actual candidates.
			if (!in_array($managerUid, array_map(trim(...), $managerUids), true)) {
				continue;
			}
			// Guests are not employees, so they are nobody's direct report — which
			// also keeps them out of getPeers() and every team-scoped view (§2.2).
			if (!$this->employees->isEmployee($uid)) {
				continue;
			}
			if ($this->firstValidManager($uid, $managerUids) === $managerUid) {
				$this->managerCache[$uid] = $managerUid;
				$reports[] = $uid;
			}
		}
		return $this->reportsCache[$managerUid] = $reports;
	}

	/**
	 * Peers of an employee: everyone sharing the same manager (excluding self).
	 * Employees with no manager have no team peers.
	 *
	 * @return string[]
	 */
	public function getPeers(string $employeeUid): array {
		$managerUid = $this->getManagerUid($employeeUid);
		if ($managerUid === null) {
			return [];
		}
		return array_values(array_filter(
			$this->getDirectReports($managerUid),
			static fn (string $uid): bool => $uid !== $employeeUid,
		));
	}

	public function isManagerOf(string $managerUid, string $employeeUid): bool {
		// Answered from the employee's own manager property: one user lookup.
		// This runs inside canView/canDecide on nearly every request, so it must
		// never pay for the size of the directory.
		return $managerUid !== ''
			&& $this->getManagerUid($employeeUid) === $managerUid
			&& $this->employees->isEmployee($employeeUid);
	}

	/**
	 * Everybody's configured manager uids in one indexed query on the stored
	 * preference — never a walk over the user backend. Only rows where the
	 * property is set at all come back, which on any real instance is far
	 * fewer values than users, and each is a tiny JSON list.
	 *
	 * @return array<string,string[]>
	 */
	private function configuredManagers(): array {
		if ($this->configuredManagers !== null) {
			return $this->configuredManagers;
		}
		$configured = [];
		foreach ($this->userConfig->getValuesByUsers(self::MANAGER_PREF_APP, self::MANAGER_PREF_KEY) as $uid => $value) {
			$decoded = is_array($value) ? $value : json_decode((string)$value, true);
			if (is_array($decoded) && $decoded !== []) {
				$configured[(string)$uid] = array_map(strval(...), $decoded);
			}
		}
		return $this->configuredManagers = $configured;
	}
}
