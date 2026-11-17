/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { BalanceRow } from '../types/index.ts'

// Below this share of the entitlement, the available days are nearly spent.
const LOW_BALANCE_RATIO = 0.2

/** The figures of a balance ring */
export interface RingFigures {
	/** The number in the centre */
	value: number
	/** The share of the circle the arc covers, 0 to 1 */
	fraction: number
}

/**
 * What the balance ring of a leave type shows: the days still free to book, and
 * the arc as their share of the entitlement.
 *
 * @param row the balance of the type
 */
export function ringFigures(row: Pick<BalanceRow, 'entitlement' | 'available'>): RingFigures {
	const value = row.available ?? 0
	if (!row.entitlement || row.entitlement <= 0) {
		return { value, fraction: 0 }
	}
	return { value, fraction: Math.min(1, Math.max(0, value / row.entitlement)) }
}

/**
 * 'neg' when the available days are overdrawn, 'low' when they are nearly
 * spent, '' otherwise.
 *
 * @param row the balance of the type, null when there is none
 */
export function balanceTone(row: Pick<BalanceRow, 'entitlement' | 'available'> | null): '' | 'low' | 'neg' {
	if (!row || row.entitlement === null || !(row.entitlement > 0)) {
		return ''
	}
	// A missing figure compares like 0
	const available = row.available ?? 0
	if (available < 0) {
		return 'neg'
	}
	return available < row.entitlement * LOW_BALANCE_RATIO ? 'low' : ''
}
