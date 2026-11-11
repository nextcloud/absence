/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { isNumeric, yearFromQuery } from './numbers.ts'

describe('isNumeric', () => {
	it('takes numbers and numbers written as text', () => {
		expect(isNumeric(0)).toBe(true)
		expect(isNumeric(12.5)).toBe(true)
		expect(isNumeric('30')).toBe(true)
		expect(isNumeric(' 2.5 ')).toBe(true)
	})

	it('refuses what an emptied number field holds', () => {
		expect(isNumeric(NaN)).toBe(false)
		expect(isNumeric('')).toBe(false)
		expect(isNumeric('   ')).toBe(false)
	})

	it('refuses text that is not a number', () => {
		expect(isNumeric('abc')).toBe(false)
		expect(isNumeric('12 days')).toBe(false)
		expect(isNumeric(Infinity)).toBe(false)
	})
})

describe('yearFromQuery', () => {
	it('reads a year', () => {
		expect(yearFromQuery('2024', 2026)).toBe(2024)
	})

	it('falls back without a year', () => {
		expect(yearFromQuery(undefined, 2026)).toBe(2026)
		expect(yearFromQuery('', 2026)).toBe(2026)
		expect(yearFromQuery(null, 2026)).toBe(2026)
	})

	it('falls back on what is not a year', () => {
		expect(yearFromQuery('abc', 2026)).toBe(2026)
		expect(yearFromQuery('2024.5', 2026)).toBe(2026)
		expect(yearFromQuery('1e3', 2026)).toBe(2026)
		expect(yearFromQuery(['2024', '2025'], 2026)).toBe(2026)
	})
})
