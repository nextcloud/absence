<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Resolves the line-manager relationship from the user's `manager` account field
 * ({@see IUser::getManagerUids()}, populated from LDAP where configured), and the
 * inverse "direct reports" set. Results are cached per request (spec §2.1).
 */
class ManagerResolver {
	/** @var array<string,?string> uid => manager uid|null */
	private array $managerCache = [];
	/** @var array<string,string[]>|null manager uid => report uids (built lazily) */
	private ?array $reportsIndex = null;

	public function __construct(
		private IUserManager $userManager,
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
			$manager = $this->readManagerUid($user);
		}
		return $this->managerCache[$employeeUid] = $manager;
	}

	/**
	 * A user may have several configured managers; we use the first valid one.
	 */
	private function readManagerUid(IUser $user): ?string {
		try {
			$managerUids = $user->getManagerUids();
		} catch (\Throwable $e) {
			$this->logger->debug('Absence: could not read manager for ' . $user->getUID(), ['exception' => $e]);
			return null;
		}
		foreach ($managerUids as $uid) {
			$uid = trim((string)$uid);
			// A guest cannot be a line manager — they have no standing in the app,
			// so routing an approval to them would strand the request (§2.2).
			if ($uid !== '' && $uid !== $user->getUID() && $this->employees->isEmployee($uid)) {
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
		return $this->getReportsIndex()[$managerUid] ?? [];
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
		return in_array($employeeUid, $this->getDirectReports($managerUid), true);
	}

	/**
	 * @return array<string,string[]>
	 */
	private function getReportsIndex(): array {
		if ($this->reportsIndex !== null) {
			return $this->reportsIndex;
		}
		$index = [];
		$this->userManager->callForAllUsers(function (IUser $user) use (&$index): void {
			// Guests are not employees, so they are nobody's direct report — which
			// also keeps them out of getPeers() and every team-scoped view (§2.2).
			if (!$this->employees->isEmployee($user->getUID())) {
				return;
			}
			$managerUid = $this->readManagerUid($user);
			if ($managerUid !== null) {
				$index[$managerUid][] = $user->getUID();
				$this->managerCache[$user->getUID()] = $managerUid;
			}
		});
		return $this->reportsIndex = $index;
	}
}
