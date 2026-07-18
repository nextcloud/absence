<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Exception\ValidationException;

/**
 * Shared validation for the `from`/`to` query parameters that reach the calendar,
 * coverage, export and report endpoints. Rejects non-`YYYY-MM-DD` input (which would
 * otherwise reach date arithmetic or an unbounded query) and caps the span so a single
 * request cannot ask the server to materialise decades of data or iterate day-by-day
 * over an arbitrary range.
 */
trait DateRangeTrait {
	/**
	 * @return array{0:string,1:string} the normalised [from, to] pair
	 * @throws ValidationException
	 */
	private function assertValidRange(string $from, string $to, int $maxDays = 3660): array {
		$parsedFrom = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
		if ($parsedFrom === false || $parsedFrom->format('Y-m-d') !== $from) {
			throw new ValidationException('Invalid start date.');
		}
		$parsedTo = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
		if ($parsedTo === false || $parsedTo->format('Y-m-d') !== $to) {
			throw new ValidationException('Invalid end date.');
		}
		if ($parsedTo < $parsedFrom) {
			throw new ValidationException('The end date must be on or after the start date.');
		}
		if ((int)$parsedFrom->diff($parsedTo)->days > $maxDays) {
			throw new ValidationException('The selected date range is too large.');
		}
		return [$parsedFrom->format('Y-m-d'), $parsedTo->format('Y-m-d')];
	}
}
