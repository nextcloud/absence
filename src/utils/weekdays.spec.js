/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { formatWeekdays } from './weekdays.js'

describe('formatWeekdays', () => {
	it('writes three or more days in a row as a range', () => {
		expect(formatWeekdays([1, 2, 3, 4, 5])).toBe('Mon–Fri')
		expect(formatWeekdays([4, 1, 2, 3])).toBe('Mon–Thu')
		expect(formatWeekdays([1, 2, 3, 5])).toBe('Mon–Wed, Fri')
	})

	it('lists shorter runs day by day', () => {
		expect(formatWeekdays([1, 3, 4])).toBe('Mon, Wed, Thu')
		expect(formatWeekdays([2])).toBe('Tue')
		expect(formatWeekdays([6, 7])).toBe('Sat, Sun')
	})
})
