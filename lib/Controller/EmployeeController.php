<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Service\EmployeeDirectory;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Share\IShare;

class EmployeeController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private IUserManager $userManager,
		private ISearch $collaboratorSearch,
		private EmployeeDirectory $employees,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Employee autocomplete for the app's people pickers (record-on-behalf,
	 * replacement, the HR absence filter).
	 *
	 * This exists instead of calling core's `core/autocomplete/get` from the
	 * client because "who is an employee" is a server-side rule: the client
	 * cannot tell a guest account from a colleague, so a client-side filter
	 * would be no filter at all (§2.2).
	 *
	 * Results still come from the collaborator search, so the admin's user
	 * enumeration settings continue to apply exactly as they do everywhere else
	 * — with enumeration restricted, that search returns nothing and so does
	 * this. Guests are then removed from whatever it did return.
	 *
	 * The one thing that search gets wrong here is *you*: it drops the searching
	 * user from its own results ({@see \OC\Collaboration\Collaborators\UserPlugin}),
	 * because it was built to answer "who can I share with" and nobody shares with
	 * themselves. This picker asks a different question — "whose absence is this?" —
	 * and there you are a valid answer. Without this, an HR member could not record
	 * their own sick leave at all: the dialog refuses to submit until an employee is
	 * chosen, and the only other route, "New request", offers just the
	 * self-requestable types, which sick leave deliberately is not (§5.6).
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function search(string $search = '', int $limit = 20): DataResponse {
		return $this->handle(function () use ($search, $limit): array {
			$search = trim($search);
			if ($search === '') {
				return [];
			}
			// Over-fetch a little: guests removed below would otherwise eat into the
			// requested number of suggestions and shorten the list for no reason.
			$limit = max(1, min($limit, 50));
			[$results] = $this->collaboratorSearch->search($search, [IShare::TYPE_USER], false, $limit * 2, 0);

			$candidates = array_merge(
				$results['exact']['users'] ?? [],
				$results['users'] ?? [],
			);

			$employees = [];
			// First, because somebody typing their own name has matched it exactly and
			// should not be pushed below looser matches on it.
			$self = $this->matchingSelf($search);
			if ($self !== null) {
				$employees[$self['uid']] = $self;
			}
			foreach ($candidates as $candidate) {
				$uid = (string)($candidate['value']['shareWith'] ?? '');
				// Exact and wide matches overlap, so the same person can appear twice.
				if ($uid === '' || isset($employees[$uid])) {
					continue;
				}
				if (!$this->employees->isEmployee($uid)) {
					continue;
				}
				$employees[$uid] = [
					'uid' => $uid,
					'displayName' => (string)($candidate['label'] ?? $uid),
				];
				if (count($employees) >= $limit) {
					break;
				}
			}
			return array_values($employees);
		});
	}

	/**
	 * The signed-in user, when they match what was typed and may hold leave.
	 *
	 * Deliberately not gated on the admin's user-enumeration settings, unlike every
	 * other result: those exist to stop people discovering *colleagues* they have no
	 * business seeing, and returning you to yourself discloses nothing you do not
	 * already know.
	 *
	 * @return ?array{uid:string,displayName:string}
	 */
	private function matchingSelf(string $search): ?array {
		$uid = (string)$this->userId;
		if ($uid === '') {
			return null;
		}
		$user = $this->userManager->get($uid);
		// A guest is not an employee and takes no leave, even when it is their own
		// account doing the searching (§2.2).
		if ($user === null || !$this->employees->isEmployee($uid)) {
			return null;
		}
		$needle = mb_strtolower($search);
		$displayName = $user->getDisplayName();
		foreach ([$uid, $displayName] as $haystack) {
			if (str_contains(mb_strtolower($haystack), $needle)) {
				return ['uid' => $uid, 'displayName' => $displayName];
			}
		}
		return null;
	}
}
