/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import {
	addWorkingDaysByMonth,
	countWorkingDays,
	formatRange,
	parseWeekdays,
	toIso,
} from './dates.js'

describe('parseWeekdays', () => {
	it('parses a CSV of ISO weekday numbers', () => {
		expect(parseWeekdays('1,3,5')).toEqual(new Set([1, 3, 5]))
	})

	it('returns an empty set for empty input', () => {
		expect(parseWeekdays('').size).toBe(0)
	})
})

describe('countWorkingDays', () => {
	const monFri = parseWeekdays('1,2,3,4,5')

	it('counts five working days in a full Mon–Sun week', () => {
		expect(countWorkingDays('2026-03-02', '2026-03-08', monFri, null)).toBe(5)
	})

	it('counts a single weekend day as zero', () => {
		expect(countWorkingDays('2026-03-07', '2026-03-07', monFri, null)).toBe(0)
	})

	it('treats a reversed range as empty', () => {
		expect(countWorkingDays('2026-03-08', '2026-03-02', monFri, null)).toBe(0)
	})

	it('subtracts a public holiday inside the range', () => {
		const isHoliday = (iso) => iso === '2026-03-03'
		expect(countWorkingDays('2026-03-02', '2026-03-06', monFri, isHoliday)).toBe(4)
	})

	it('honours a custom working-day pattern', () => {
		expect(countWorkingDays('2026-03-02', '2026-03-08', parseWeekdays('1,3,5'), null)).toBe(3)
	})

	it('counts nothing when no weekday is a working day', () => {
		expect(countWorkingDays('2026-03-02', '2026-03-08', parseWeekdays(''), null)).toBe(0)
	})

	it('spans a month boundary', () => {
		// Mon 30 Mar – Fri 3 Apr 2026 is five consecutive working days.
		expect(countWorkingDays('2026-03-30', '2026-04-03', monFri, null)).toBe(5)
	})

	it('spans a leap day', () => {
		// 2028 is a leap year: Mon 28 Feb – Wed 1 Mar covers 29 Feb.
		expect(countWorkingDays('2028-02-28', '2028-03-01', monFri, null)).toBe(3)
	})
})

describe('toIso', () => {
	it('formats in local time, not UTC', () => {
		// Late-evening local times must not roll over to the next day.
		expect(toIso(new Date(2026, 2, 7, 23, 30))).toBe('2026-03-07')
	})

	it('zero-pads month and day', () => {
		expect(toIso(new Date(2026, 0, 5))).toBe('2026-01-05')
	})
})

describe('formatRange', () => {
	it('renders a single day without a separator', () => {
		expect(formatRange('2026-03-02', '2026-03-02')).not.toContain('–')
	})

	it('renders a multi-day range with both ends', () => {
		expect(formatRange('2026-03-02', '2026-03-04')).toContain('–')
	})
})

describe('addWorkingDaysByMonth', () => {
	const empty = () => new Array(12).fill(0)

	it('splits a request across months by the calendar days in each', () => {
		// 30–31 Mar and 1–3 Apr: 5 days total, so 2/5 to March and 3/5 to April.
		const buckets = empty()
		addWorkingDaysByMonth(buckets, '2026-03-30', '2026-04-03', 5, 2026)
		expect(buckets[2]).toBeCloseTo(2)
		expect(buckets[3]).toBeCloseTo(3)
		expect(buckets.reduce((a, b) => a + b, 0)).toBeCloseTo(5)
	})

	it('keeps a single-month request whole', () => {
		const buckets = empty()
		addWorkingDaysByMonth(buckets, '2026-03-02', '2026-03-06', 5, 2026)
		expect(buckets[2]).toBeCloseTo(5)
	})

	it('ignores requests outside the reported year', () => {
		const buckets = empty()
		addWorkingDaysByMonth(buckets, '2025-03-02', '2025-03-06', 5, 2026)
		expect(buckets.reduce((a, b) => a + b, 0)).toBe(0)
	})

	it('adds nothing when the working-day count is zero', () => {
		const buckets = empty()
		addWorkingDaysByMonth(buckets, '2026-03-02', '2026-03-06', 0, 2026)
		expect(buckets.reduce((a, b) => a + b, 0)).toBe(0)
	})
})
