/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { contractDiff, describePeriod } from './contract.js'

const fullTime = { from: '2024-03-01', weekdays: [1, 2, 3, 4, 5], percent: 100 }
const fourDays = { from: '2026-07-01', weekdays: [1, 2, 3, 4], percent: 80 }
const backToFull = { from: '2027-01-01', weekdays: [1, 2, 3, 4, 5], percent: 100 }

describe('describePeriod', () => {
	it('names the days and the percentage when there is one', () => {
		expect(describePeriod(fourDays)).toBe('Mon–Thu, 80%')
		expect(describePeriod({ ...fourDays, percent: null })).toBe('Mon–Thu')
	})
})

describe('contractDiff', () => {
	it('shows the period an added one follows', () => {
		expect(contractDiff([fullTime, fourDays], [fullTime, fourDays, backToFull])).toEqual({ removed: [], added: [backToFull], kept: [fourDays] })
	})

	it('lists what a replaced period was', () => {
		const changed = { ...fourDays, percent: 60 }
		expect(contractDiff([fullTime, fourDays], [fullTime, changed])).toEqual({ removed: [fourDays], added: [changed], kept: [] })
	})

	it('has nothing to show for a first contract', () => {
		expect(contractDiff([], [fullTime])).toEqual({ removed: [], added: [fullTime], kept: [] })
	})
})
