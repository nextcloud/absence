<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;

/**
 * Where "today" and "this year" come from.
 *
 * Nextcloud sets PHP's default timezone to UTC for the whole request, so a bare
 * `date('Y-m-d')` answers in UTC no matter where anybody is. For a timestamp
 * that is fine. For a *day boundary* compared against a date the user typed it
 * is not: at 09:00 on 2 January in Auckland it is still 1 January in UTC, so an
 * employee is told their leave "is entirely in the past" for a day that has not
 * finished where they live. Berlin has the same problem in the other direction
 * for the last hour of the day.
 *
 * Which boundary is correct depends on who is asking, so the two are separate
 * methods rather than one ambiguous `today()`:
 *
 *  - `userToday()` / `userYear()` — for anything an employee sees or is judged
 *    against, resolved in their own timezone.
 *  - `serverToday()` / `serverYear()` — for background jobs and company-wide
 *    policy, where there is no user to ask and the server's configured timezone
 *    is the only sensible answer.
 *
 * The instant comes from ITimeFactory so tests can pin it.
 */
class ClockService {
	public function __construct(
		private ITimeFactory $timeFactory,
		private IDateTimeZone $dateTimeZone,
	) {
	}

	/** Today as the signed-in user sees it, 'Y-m-d'. */
	public function userToday(): string {
		return $this->userNow()->format('Y-m-d');
	}

	/** The calendar year the signed-in user is currently in. */
	public function userYear(): int {
		return (int)$this->userNow()->format('Y');
	}

	/** Today in the server's configured timezone, 'Y-m-d'. */
	public function serverToday(): string {
		return $this->serverNow()->format('Y-m-d');
	}

	/** The calendar year the server is currently in. */
	public function serverYear(): int {
		return (int)$this->serverNow()->format('Y');
	}

	/**
	 * The current instant for a stored timestamp column (created_at, decided_at …).
	 *
	 * Deliberately UTC and not one of the two day-boundary methods above: a
	 * timestamp records *when* something happened, which is the same moment for
	 * everyone, so there is no user or server timezone to pick. It exists only so
	 * the workflow's timestamps come from the same pinnable clock as everything
	 * else — `new \DateTime()` cannot be frozen in a test. Mutable because the
	 * entity setters take \DateTime.
	 */
	public function now(): \DateTime {
		return \DateTime::createFromImmutable($this->at(new \DateTimeZone('UTC')));
	}

	public function userNow(): \DateTimeImmutable {
		return $this->at($this->dateTimeZone->getTimeZone());
	}

	public function serverNow(): \DateTimeImmutable {
		return $this->at($this->dateTimeZone->getDefaultTimeZone());
	}

	private function at(\DateTimeZone $timeZone): \DateTimeImmutable {
		return (new \DateTimeImmutable('@' . $this->timeFactory->getTime()))->setTimezone($timeZone);
	}
}
