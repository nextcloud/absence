<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Who counts as an employee (§2.2).
 *
 * Every part of the app that needs "the people this app is about" asks here,
 * rather than enumerating `IUserManager` itself. Previously four services each
 * had their own copy of that loop, so any rule about who is *not* an employee
 * had to be repeated four times to hold — and would silently not hold wherever
 * it was forgotten.
 *
 * Two rules decide the answer:
 *
 *  - **Guest accounts are never employees**: they are external people invited
 *    to share files, they have no entitlement, and they do not take leave.
 *    Listing them would put every guest in the balances report and the
 *    who's-off calendar with an empty allowance forever.
 *  - **An optional employees group** (§12) can narrow "everyone else" down to
 *    the members of one Nextcloud group. Left empty — the default — every
 *    non-guest account is an employee. Configured, it keeps service and
 *    functional accounts out of reports, pickers and the who's-off views, and
 *    it also bounds what {@see listAll()} has to enumerate: reading one group
 *    beats walking the whole user backend on a large LDAP instance.
 *
 * A guest is a user in the Guests app's own user backend, which is what
 * `OCA\Guests\GuestManager::isGuest()` checks too. Reading the backend name
 * keeps this app free of a hard dependency on the Guests app: with the app
 * disabled or absent no user has that backend, and the check is simply never
 * true.
 */
class EmployeeDirectory {
	/**
	 * Backend name reported by `OCA\Guests\UserBackend::getBackendName()`.
	 * `IUser::getBackendClassName()` returns it for any guest account.
	 */
	private const GUEST_BACKEND = 'Guests';

	/** @var array<string,bool> uid => is a guest, memoised per request */
	private array $guestCache = [];
	/** Employees-group id resolved once per request; null = not resolved yet. */
	private ?string $employeesGroup = null;

	public function __construct(
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private ConfigService $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Every employee on the instance.
	 *
	 * @return string[]
	 */
	public function listAll(): array {
		$group = $this->effectiveEmployeesGroup();
		if ($group !== '') {
			// The group *is* the directory: enumerate its members instead of the
			// whole user backend, which is also much cheaper on large instances.
			$resolved = $this->groupManager->get($group);
			$uids = [];
			foreach ($resolved?->getUsers() ?? [] as $user) {
				if (!$this->isGuestUser($user)) {
					$uids[] = $user->getUID();
				}
			}
			return $uids;
		}
		$uids = [];
		$this->userManager->callForAllUsers(function (IUser $user) use (&$uids): void {
			if ($this->isEmployeeUser($user)) {
				$uids[] = $user->getUID();
			}
		});
		return $uids;
	}

	/**
	 * The employees in a group, or everyone when no group is given. A group that
	 * does not exist yields no one, which keeps a stale group name in a report
	 * filter from silently widening to the whole company.
	 *
	 * @return string[]
	 */
	public function listInGroup(?string $group): array {
		if ($group === null || $group === '') {
			return $this->listAll();
		}
		$resolved = $this->groupManager->get($group);
		if ($resolved === null) {
			return [];
		}
		$uids = [];
		foreach ($resolved->getUsers() as $user) {
			if ($this->isEmployeeUser($user)) {
				$uids[] = $user->getUID();
			}
		}
		return $uids;
	}

	/**
	 * Whether this uid belongs to somebody the app may hold leave for. False for
	 * a guest, for an account outside a configured employees group, and for a
	 * uid that does not resolve to a user at all.
	 */
	public function isEmployee(string $uid): bool {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			// An unknown uid is not a statement about guest-ness, and the user
			// may be created later in a long-running job.
			return false;
		}
		return $this->isEmployeeUser($user);
	}

	/** Whether this uid is a guest account specifically (an unknown uid is not). */
	public function isGuest(string $uid): bool {
		$user = $this->userManager->get($uid);
		return $user !== null && $this->isGuestUser($user);
	}

	/**
	 * Filter a list of uids down to the employees in it, preserving order.
	 *
	 * @param string[] $uids
	 * @return string[]
	 */
	public function filter(array $uids): array {
		return array_values(array_filter($uids, fn (string $uid): bool => $this->isEmployee($uid)));
	}

	private function isEmployeeUser(IUser $user): bool {
		if ($this->isGuestUser($user)) {
			return false;
		}
		$group = $this->effectiveEmployeesGroup();
		return $group === '' || $this->groupManager->isInGroup($user->getUID(), $group);
	}

	private function isGuestUser(IUser $user): bool {
		$uid = $user->getUID();
		if (!array_key_exists($uid, $this->guestCache)) {
			$this->guestCache[$uid] = $user->getBackendClassName() === self::GUEST_BACKEND;
		}
		return $this->guestCache[$uid];
	}

	/**
	 * The configured employees group, or '' when none is set *or the configured
	 * one no longer exists*. Failing open on a deleted group is deliberate: the
	 * group gates who may book leave at all, so failing closed would silently
	 * freeze the app for the whole company. The admin is told loudly instead —
	 * the setter refuses unknown groups, so this only happens when a configured
	 * group is deleted later.
	 */
	private function effectiveEmployeesGroup(): string {
		if ($this->employeesGroup !== null) {
			return $this->employeesGroup;
		}
		$group = $this->config->getEmployeesGroup();
		if ($group !== '' && $this->groupManager->get($group) === null) {
			$this->logger->error('Absence: the configured employees group does not exist — treating every account as an employee', [
				'app' => 'absence',
				'employeesGroup' => $group,
			]);
			$group = '';
		}
		return $this->employeesGroup = $group;
	}
}
