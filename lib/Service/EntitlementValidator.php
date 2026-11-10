<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Exception\ValidationException;
use OCP\IL10N;

/**
 * What an entitlement may hold (§6.1), for the API and the CSV import alike.
 */
class EntitlementValidator {
	/** The largest value the DECIMAL(5,1) columns store. */
	private const MAX_DAYS = 9999.9;

	public function __construct(
		private IL10N $l,
	) {
	}

	/**
	 * @throws ValidationException
	 */
	public function assertYear(int $year): void {
		if ($year < 2000 || $year > 2100) {
			throw new ValidationException($this->l->t('The year must be between 2000 and 2100.'));
		}
	}

	/**
	 * Day counts are stored with one decimal: a finer value is refused rather
	 * than rounded, so what was sent is what is stored.
	 *
	 * @param array<string,mixed> $data the figures by name, other keys are ignored
	 * @throws ValidationException
	 */
	public function assertFigures(array $data): void {
		foreach (['baseDays', 'carryOverDays', 'manualAdjustment', 'adjustmentDelta'] as $key) {
			if (!array_key_exists($key, $data)) {
				continue;
			}
			$tenths = (float)$data[$key] * 10.0;
			if (!is_finite($tenths) || abs($tenths) > self::MAX_DAYS * 10.0 || abs($tenths - round($tenths)) > 0.0001) {
				throw new ValidationException($this->l->t('Day counts must be between -9999.9 and 9999.9, with one decimal at most.'));
			}
		}
		if ((float)($data['baseDays'] ?? 0.0) < 0.0) {
			throw new ValidationException($this->l->t('The base entitlement cannot be negative.'));
		}
	}
}
