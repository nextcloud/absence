import type { PrefillForm } from './prefill.ts'

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { defaultLeaveType, estimateMismatch, prefilledWorkingDays } from './prefill.ts'

const monToFri = new Set([1, 2, 3, 4, 5])
// Monday 2 to Friday 6 March 2026, saved by hand as 4.5 days.
const saved = { startDate: '2026-03-02', endDate: '2026-03-06', workingDays: 4.5 }
const form = (values: Partial<PrefillForm>) => prefilledWorkingDays({ request: null, touched: false, startIso: '2026-03-02', endIso: '2026-03-06', weekdays: monToFri, isHoliday: null, ...values })

describe('prefilledWorkingDays', () => {
	it('counts the working days of a new request', () => {
		expect(form({})).toBe('5')
	})

	it('keeps the stored count when a saved request opens', () => {
		expect(form({ request: saved })).toBe('4.5')
	})

	it('counts again when the dates of a saved request change', () => {
		expect(form({ request: saved, endIso: '2026-03-13' })).toBe('10')
	})

	it('shows the stored count again when the dates go back to the saved ones', () => {
		expect(form({ request: saved, startIso: saved.startDate, endIso: saved.endDate })).toBe('4.5')
	})

	it('leaves a number typed by hand alone, new or saved', () => {
		expect(form({ touched: true })).toBeNull()
		expect(form({ request: saved, endIso: '2026-03-13', touched: true })).toBeNull()
	})

	it('leaves the field alone while a date is missing', () => {
		expect(form({ endIso: null })).toBeNull()
	})

	it('empties the field when the working days of the person are unknown', () => {
		expect(form({ weekdays: null })).toBe('')
	})

	it('uses the weekdays and holidays it is given, not a default', () => {
		const monToThu = new Set([1, 2, 3, 4])
		expect(form({ weekdays: monToThu })).toBe('4')
		expect(form({ weekdays: monToThu, isHoliday: (iso: string) => iso === '2026-03-03' })).toBe('3')
	})
})

describe('estimateMismatch', () => {
	const check = (entered: number, values: Partial<PrefillForm>) => estimateMismatch({ request: null, touched: true, startIso: '2026-03-02', endIso: '2026-03-06', weekdays: monToFri, isHoliday: null, ...values }, entered)

	it('stays quiet while the prefilled count is shown', () => {
		expect(check(5, { touched: false })).toBeNull()
	})

	it('stays quiet when the number typed is the estimate', () => {
		expect(check(5, {})).toBeNull()
	})

	it('gives the estimate when the number typed differs from it', () => {
		expect(check(3, {})).toBe(5)
		expect(check(4, { isHoliday: (iso: string) => iso === '2026-03-03' })).toBeNull()
	})

	it('has nothing to compare with when the working days or a date are unknown', () => {
		expect(check(3, { weekdays: null })).toBeNull()
		expect(check(3, { endIso: null })).toBeNull()
	})
})

describe('defaultLeaveType', () => {
	const annual = { key: 'annual' }
	const sick = { key: 'sick' }

	it('starts on the first type for a request', () => {
		expect(defaultLeaveType([annual, sick], false)).toBe(annual)
	})

	it('starts on sick leave when HR records an absence', () => {
		expect(defaultLeaveType([annual, sick], true)).toBe(sick)
	})

	it('falls back to the first type when there is no sick leave', () => {
		expect(defaultLeaveType([annual], true)).toBe(annual)
		expect(defaultLeaveType([], true)).toBeNull()
	})
})
