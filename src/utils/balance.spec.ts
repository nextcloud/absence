/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { BalanceRow } from '../types/index.ts'

import { describe, expect, it } from 'vitest'
import { balanceTone, ringFigures } from './balance.ts'

type Figures = Pick<BalanceRow, 'entitlement' | 'used' | 'pending' | 'remaining' | 'available'>

/**
 * A balance with its derived figures, as the server computes them.
 *
 * @param entitlement the days of the year
 * @param used approved days
 * @param pending days awaiting a decision
 */
function balance(entitlement: number, used: number, pending: number): Figures {
	return { entitlement, used, pending, remaining: entitlement - used, available: entitlement - used - pending }
}

describe('ringFigures', () => {
	it('shows the days still free to book, as a share of the entitlement', () => {
		expect(ringFigures(balance(28, 15, 4))).toEqual({ value: 9, fraction: 9 / 28 })
	})

	it('draws a full ring for a year with nothing taken', () => {
		expect(ringFigures(balance(33, 0, 0))).toEqual({ value: 33, fraction: 1 })
	})

	it('draws no arc once the balance is overdrawn', () => {
		expect(ringFigures(balance(20, 18, 5))).toEqual({ value: -3, fraction: 0 })
	})

	it('draws no arc without an entitlement', () => {
		expect(ringFigures(balance(0, 2, 0))).toEqual({ value: -2, fraction: 0 })
	})
})

describe('balanceTone', () => {
	it('counts pending days as booked', () => {
		expect(balanceTone(balance(28, 15, 10))).toBe('low')
		expect(balanceTone(balance(28, 26, 3))).toBe('neg')
		expect(balanceTone(balance(28, 15, 0))).toBe('')
	})

	it('says nothing without an entitlement', () => {
		expect(balanceTone(null)).toBe('')
		expect(balanceTone({ entitlement: null, available: null })).toBe('')
	})
})
