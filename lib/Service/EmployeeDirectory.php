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

/**
 * Who counts as an employee (§2.2).
 *
 * Every part of the app that needs "the people this app is about" asks here,
 * rather than enumerating `IUserManager` itself. Previously four services each
 * had their own copy of that loop, so any rule about who is *not* an employee
 * had to be repeated four times to hold — and would silently not hold wherever
 * it was forgotten.
 *
 * The one rule today is that **guest accounts are not employees**: they are
 * external people invited to share files, they have no entitlement, and they do
 * not take leave. Listing them would put every guest in the balances report and
 * the who's-off calendar with an empty allowance forever.
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

	public function __construct(
		private IUserManager $userManager,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * Every employee on the instance.
	 *
	 * @return string[]
	 */
	public function listAll(): array {
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
	 * a guest, and for a uid that does not resolve to a user at all.
	 */
	public function isEmployee(string $uid): bool {
		if (array_key_exists($uid, $this->guestCache)) {
			return !$this->guestCache[$uid];
		}
		$user = $this->userManager->get($uid);
		if ($user === null) {
			// Not cached: an unknown uid is not a statement about guest-ness, and
			// the user may be created later in a long-running job.
			return false;
		}
		return $this->isEmployeeUser($user);
	}

	/** Whether this uid is a guest account specifically (an unknown uid is not). */
	public function isGuest(string $uid): bool {
		$user = $this->userManager->get($uid);
		return $user !== null && !$this->isEmployeeUser($user);
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
		$uid = $user->getUID();
		if (!array_key_exists($uid, $this->guestCache)) {
			$this->guestCache[$uid] = $user->getBackendClassName() === self::GUEST_BACKEND;
		}
		return !$this->guestCache[$uid];
	}
}
