<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Exception\ValidationException;

/**
 * The checks on a hand-entered working-day count (§7), shared by requests and
 * the leave import so both accept exactly the same numbers.
 */
trait WorkingDaysGuardTrait {
	// Every class using this trait injects IL10N as $this->l.

	/**
	 * The employee enters the working-day count manually (§7). Validate it is a
	 * positive, sane number of full days (§3.3). A count saved before with a
	 * half day is accepted unchanged, so editing such a request keeps working.
	 *
	 * @param ?float $stored the count saved for the request being edited
	 * @throws ValidationException
	 */
	private function normaliseWorkingDays(mixed $value, ?float $stored = null): float {
		if ($value === null || $value === '' || !is_numeric($value)) {
			throw new ValidationException($this->l->t('Please enter the number of working days.'));
		}
		$days = (float)$value;
		if ($stored !== null && $days === $stored) {
			return $days;
		}
		if ($days !== floor($days)) {
			throw new ValidationException($this->l->t('Enter the working days as a whole number: leave is taken in full days.'));
		}
		if ($days <= 0) {
			throw new ValidationException($this->l->t('The number of working days must be greater than zero.'));
		}
		if ($days > 366) {
			throw new ValidationException($this->l->t('That is too many working days for a single request.'));
		}
		return $days;
	}

	/**
	 * A leave of N calendar days cannot contain more than N working days. The
	 * count is entered by hand (§7), so this catches the typo (40 for 4) that
	 * would otherwise go straight into the balance, while never rejecting a
	 * legitimate count, which is at most one working day per calendar day.
	 *
	 * @throws ValidationException
	 */
	private function assertWorkingDaysFitRange(float $workingDays, string $start, string $end): void {
		$span = ((int)(new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days) + 1;
		if ($workingDays > (float)$span) {
			throw new ValidationException($this->l->t('The number of working days cannot exceed the length of the leave (%s calendar days).', [(string)$span]));
		}
	}
}
