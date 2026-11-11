/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { crossesYear, restAfter, splitAtYearEnd } from './yearSplit.ts'

describe('crossesYear', () => {
	it('is true when the range ends in a later year', () => {
		expect(crossesYear('2026-12-22', '2027-01-05')).toBe(true)
		expect(crossesYear('2026-12-31', '2027-01-01')).toBe(true)
		expect(crossesYear('2026-06-01', '2028-02-01')).toBe(true)
	})

	it('is false within one year, up to its last day', () => {
		expect(crossesYear('2026-01-01', '2026-12-31')).toBe(false)
		expect(crossesYear('2026-12-31', '2026-12-31')).toBe(false)
	})

	it('is false for an incomplete or reversed range', () => {
		expect(crossesYear(null, '2027-01-05')).toBe(false)
		expect(crossesYear('2026-12-22', null)).toBe(false)
		expect(crossesYear('2027-01-05', '2026-12-22')).toBe(false)
	})
})

describe('splitAtYearEnd', () => {
	it('cuts at 31 December of the start year and keeps the rest', () => {
		expect(splitAtYearEnd('2026-12-22', '2027-01-05')).toEqual({
			firstEnd: '2026-12-31',
			rest: { startIso: '2027-01-01', endIso: '2027-01-05' },
		})
	})

	it('handles a range of two days around New Year', () => {
		expect(splitAtYearEnd('2026-12-31', '2027-01-01')).toEqual({
			firstEnd: '2026-12-31',
			rest: { startIso: '2027-01-01', endIso: '2027-01-01' },
		})
	})

	it('splits off only the first year of a longer range', () => {
		const split = splitAtYearEnd('2026-11-01', '2028-03-15')
		expect(split).toEqual({
			firstEnd: '2026-12-31',
			rest: { startIso: '2027-01-01', endIso: '2028-03-15' },
		})
		// The rest crosses a New Year again: the follow-up form offers the split once more
		expect(splitAtYearEnd('2027-01-01', '2028-03-15')).toEqual({
			firstEnd: '2027-12-31',
			rest: { startIso: '2028-01-01', endIso: '2028-03-15' },
		})
	})

	it('does not cut a range within one year, an incomplete or a reversed one', () => {
		expect(splitAtYearEnd('2026-12-01', '2026-12-31')).toBeNull()
		expect(splitAtYearEnd(null, '2027-01-05')).toBeNull()
		expect(splitAtYearEnd('2026-12-22', null)).toBeNull()
		expect(splitAtYearEnd('2027-01-05', '2026-12-22')).toBeNull()
	})
})

describe('restAfter', () => {
	const split = { firstEnd: '2026-12-31', rest: { startIso: '2027-01-01', endIso: '2027-01-05' } }

	it('is the rest while the picked range is still the first part', () => {
		expect(restAfter(split, '2026-12-22', '2026-12-31')).toEqual(split.rest)
		// A start moved within the same year is still the first part
		expect(restAfter(split, '2026-12-28', '2026-12-31')).toEqual(split.rest)
	})

	it('is nothing once the end moved off 31 December', () => {
		expect(restAfter(split, '2026-12-22', '2026-12-30')).toBeNull()
		expect(restAfter(split, '2026-12-22', '2027-01-05')).toBeNull()
		expect(restAfter(split, '2026-12-22', null)).toBeNull()
	})

	it('is nothing once the start left the year, or was cleared', () => {
		expect(restAfter(split, '2025-12-22', '2026-12-31')).toBeNull()
		expect(restAfter(split, null, '2026-12-31')).toBeNull()
	})

	it('is nothing without a split', () => {
		expect(restAfter(null, '2026-12-22', '2026-12-31')).toBeNull()
	})
})
