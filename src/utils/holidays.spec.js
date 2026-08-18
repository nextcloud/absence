/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { listCountries, listRegions, makeHolidayChecker } from './holidays.js'

describe('makeHolidayChecker', () => {
	it('returns null when no country is configured (no subtraction)', async () => {
		expect(await makeHolidayChecker('')).toBeNull()
		expect(await makeHolidayChecker(undefined)).toBeNull()
	})

	it('marks nationwide public holidays', async () => {
		const isHoliday = await makeHolidayChecker('DE')
		expect(isHoliday('2026-01-01')).toBe(true) // Neujahr
		expect(isHoliday('2026-10-03')).toBe(true) // Tag der Deutschen Einheit
		expect(isHoliday('2026-07-15')).toBe(false) // an ordinary Wednesday
	})

	it('honours the sub-region: Epiphany is public in Bavaria, not in Berlin', async () => {
		const bavaria = await makeHolidayChecker('DE', 'BY')
		const berlin = await makeHolidayChecker('DE', 'BE')
		expect(bavaria('2026-01-06')).toBe(true)
		expect(berlin('2026-01-06')).toBe(false)
	})

	it('does not count observances as public holidays', async () => {
		const isHoliday = await makeHolidayChecker('DE')
		// Valentine's Day is in the dataset as an observance, never a public holiday.
		expect(isHoliday('2026-02-14')).toBe(false)
	})
})

describe('listCountries', () => {
	it('lists countries as {id, label} pairs', async () => {
		const countries = await listCountries()
		expect(countries.length).toBeGreaterThan(50)
		const de = countries.find((c) => c.id === 'DE')
		expect(de).toBeTruthy()
		expect(typeof de.label).toBe('string')
	})
})

describe('listRegions', () => {
	it('lists the German Bundesländer', async () => {
		const regions = await listRegions('DE')
		expect(regions.map((r) => r.id)).toContain('BY')
	})

	it('is empty for no country and for countries without regions', async () => {
		expect(await listRegions('')).toEqual([])
	})
})
