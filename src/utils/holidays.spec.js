/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { compareHolidays, groupByYear, hasHolidays, makeHolidayChecker, parseRegionCode } from './holidays.js'

describe('parseRegionCode', () => {
	it('splits and uppercases a code', () => {
		expect(parseRegionCode(' de-by ')).toEqual({ country: 'DE', region: 'BY' })
		expect(parseRegionCode('DE_BY')).toEqual({ country: 'DE', region: 'BY' })
		expect(parseRegionCode('FR')).toEqual({ country: 'FR', region: '' })
		expect(parseRegionCode('')).toEqual({ country: '', region: '' })
	})

	it('rejects what is not a code', () => {
		expect(parseRegionCode('Bavaria')).toBeNull()
		expect(parseRegionCode('DE-BAVARIA')).toBeNull()
		expect(parseRegionCode('*')).toBeNull()
	})
})

describe('hasHolidays', () => {
	it('counts holidays entered for the country of a region', () => {
		expect(hasHolidays('DE-BY', ['*', 'DE'])).toBe(true)
		expect(hasHolidays('DE-BY', ['*', 'DE-BY'])).toBe(true)
		expect(hasHolidays('IN', ['*', 'DE'])).toBe(false)
	})
})

describe('compareHolidays', () => {
	it('lists the days lost and gained, and counts the rest', () => {
		const bavaria = [
			{ date: '2027-01-01', label: 'New Year' },
			{ date: '2027-01-06', label: 'Epiphany' },
			{ date: '2027-08-15', label: 'Assumption Day' },
		]
		const berlin = [
			{ date: '2027-01-01', label: 'New Year' },
			{ date: '2027-03-08', label: 'Women\'s Day' },
		]
		expect(compareHolidays(bavaria, berlin)).toEqual({
			lost: [{ date: '2027-01-06', label: 'Epiphany' }, { date: '2027-08-15', label: 'Assumption Day' }],
			gained: [{ date: '2027-03-08', label: 'Women\'s Day' }],
			kept: 1,
		})
	})
})

describe('makeHolidayChecker', () => {
	it('is true on the listed days only', () => {
		const isHoliday = makeHolidayChecker([{ date: '2027-01-01' }])
		expect(isHoliday('2027-01-01')).toBe(true)
		expect(isHoliday('2027-01-02')).toBe(false)
	})
})

describe('groupByYear', () => {
	it('splits days by year, in date order', () => {
		expect(groupByYear([
			{ date: '2027-01-06', label: 'Epiphany' },
			{ date: '2026-08-15', label: 'Assumption Day' },
			{ date: '2026-01-06', label: 'Epiphany' },
		])).toEqual([
			{ year: '2026', days: [{ date: '2026-01-06', label: 'Epiphany' }, { date: '2026-08-15', label: 'Assumption Day' }] },
			{ year: '2027', days: [{ date: '2027-01-06', label: 'Epiphany' }] },
		])
	})
})
