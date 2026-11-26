<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserBackend;
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
 * A **disabled account** is still an employee here: HR keeps the records of
 * people who left, can correct them and exports them. Everything about the
 * people who work here now (reports, coverage, pickers, the team calendar,
 * reminders and the rollover) asks for the *active* employees instead
 * ({@see listActive()}, {@see isActive()}), which leave disabled accounts out.
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
	/** @var array<string,true>|null uid => disabled, read once per request */
	private ?array $disabled = null;

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
		return $this->listForEmployeesGroup($this->effectiveEmployeesGroup());
	}

	/**
	 * Every employee's display name, by uid. Where {@see listAll()} resolves the
	 * accounts one by one, this reads the employees group (or every account,
	 * without one) and the guest accounts once each.
	 *
	 * @return array<string, string>
	 */
	public function displayNames(): array {
		$group = $this->effectiveEmployeesGroup();
		if ($group !== '') {
			$names = $this->groupManager->displayNamesInGroup($group);
		} else {
			$names = [];
			foreach ($this->userManager->searchDisplayName('') as $user) {
				$names[$user->getUID()] = $user->getDisplayName();
			}
		}
		return array_diff_key($names, array_flip($this->guestUids()));
	}

	/**
	 * Who the employees would be with this employees group, '' meaning every
	 * non-guest account. Answers "who joins and who leaves" before the setting
	 * is changed.
	 *
	 * @return string[]
	 */
	public function listForEmployeesGroup(string $group): array {
		$uids = [];
		$collect = function (IUser $user) use (&$uids): void {
			if (!$this->isGuestUser($user)) {
				$uids[] = $user->getUID();
			}
		};
		if ($group !== '') {
			// The group *is* the directory: enumerate its members instead of the
			// whole user backend, which is also much cheaper on large instances.
			foreach ($this->groupManager->get($group)?->getUsers() ?? [] as $user) {
				$collect($user);
			}
			return $uids;
		}
		$this->userManager->callForAllUsers($collect);
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

	/**
	 * The uid behind a uid or an e-mail address, as files from spreadsheets
	 * carry either. Addresses are looked up first: the server's own user lookup
	 * accepts an address too, but then reports the address itself as the uid.
	 * Null for nobody, and for an address several accounts share. The uid is the
	 * account's own spelling, whatever casing the file used.
	 */
	public function resolveUser(string $identifier): ?string {
		if ($identifier === '') {
			return null;
		}
		if (str_contains($identifier, '@')) {
			$byEmail = $this->userManager->getByEmail($identifier);
			if ($byEmail !== []) {
				return count($byEmail) === 1 ? $byEmail[0]->getUID() : null;
			}
		}
		return $this->userManager->get($identifier)?->getUID();
	}

	/**
	 * The account's own spelling of a uid, as the user backend finds an account
	 * whatever letter case is given. Null when no account has this uid. An
	 * e-mail address is not a uid here, although the backend finds the account
	 * by it: only a file may name somebody that way ({@see resolveUser()}).
	 */
	public function canonicalUid(string $uid): ?string {
		$canonical = $this->userManager->get($uid)?->getUID();
		return $canonical !== null && mb_strtolower($canonical) === mb_strtolower($uid) ? $canonical : null;
	}

	/** Whether this uid is a guest account specifically (an unknown uid is not). */
	public function isGuest(string $uid): bool {
		$user = $this->userManager->get($uid);
		return $user !== null && $this->isGuestUser($user);
	}

	/**
	 * Every employee whose account is enabled: the people who work here now.
	 *
	 * @return string[]
	 */
	public function listActive(): array {
		return $this->withoutDisabled($this->listAll());
	}

	/**
	 * {@see listInGroup()}, without the disabled accounts.
	 *
	 * @return string[]
	 */
	public function listActiveInGroup(?string $group): array {
		return $this->withoutDisabled($this->listInGroup($group));
	}

	/**
	 * {@see displayNames()}, without the disabled accounts.
	 *
	 * @return array<string, string>
	 */
	public function activeDisplayNames(): array {
		return array_diff_key($this->displayNames(), $this->disabledUids());
	}

	/** Whether this uid is an employee whose account is enabled. */
	public function isActive(string $uid): bool {
		return $this->isEmployee($uid) && !isset($this->disabledUids()[$uid]);
	}

	/** Whether this uid's account is disabled, false for an unknown uid. */
	public function isDisabled(string $uid): bool {
		return isset($this->disabledUids()[$uid]);
	}

	/**
	 * {@see filter()}, without the disabled accounts.
	 *
	 * @param string[] $uids
	 * @return string[]
	 */
	public function filterActive(array $uids): array {
		return $this->withoutDisabled($this->filter($uids));
	}

	/**
	 * @param string[] $uids
	 * @return string[]
	 */
	private function withoutDisabled(array $uids): array {
		$disabled = $this->disabledUids();
		return array_values(array_filter($uids, static fn (string $uid): bool => !isset($disabled[$uid])));
	}

	/**
	 * The disabled accounts, read in one call rather than account by account,
	 * which on LDAP would cost a lookup per person.
	 *
	 * @return array<string,true>
	 */
	private function disabledUids(): array {
		if ($this->disabled === null) {
			$this->disabled = [];
			foreach ($this->userManager->getDisabledUsers() as $user) {
				$this->disabled[$user->getUID()] = true;
			}
		}
		return $this->disabled;
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

	/**
	 * The uids in the Guests app's user backend, none without the app.
	 *
	 * @return list<string>
	 */
	private function guestUids(): array {
		foreach ($this->userManager->getBackends() as $backend) {
			if ($backend instanceof IUserBackend && $backend->getBackendName() === self::GUEST_BACKEND) {
				return array_map(strval(...), $backend->getUsers());
			}
		}
		return [];
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
			$this->logger->error('Absence: the configured employees group does not exist, treating every account as an employee', [
				'app' => 'absence',
				'employeesGroup' => $group,
			]);
			$group = '';
		}
		return $this->employeesGroup = $group;
	}
}
