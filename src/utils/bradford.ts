/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** The conventional Bradford Factor score from which a review is due. */
export const BRADFORD_REVIEW = 500

/** The conventional Bradford Factor score from which the case is serious. */
export const BRADFORD_SERIOUS = 900

export type BradfordLevel = 'low' | 'review' | 'serious'

/**
 * Where a Bradford Factor score stands against the review thresholds.
 *
 * @param score spells² × days
 */
export function bradfordLevel(score: number): BradfordLevel {
	if (score >= BRADFORD_SERIOUS) {
		return 'serious'
	}
	return score >= BRADFORD_REVIEW ? 'review' : 'low'
}
