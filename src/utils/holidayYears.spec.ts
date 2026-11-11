/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { missingYears } from './holidayYears.ts'

describe('missingYears', () => {
	it('asks for the year of a range when nothing is loaded', () => {
		expect(missingYears('2026-03-02', '2026-03-06', new Set())).toEqual([2026])
	})

	it('asks for nothing when the years of the range are loaded', () => {
		expect(missingYears('2026-12-28', '2027-01-08', new Set([2025, 2026, 2027]))).toEqual([])
	})

	it('asks for the years of a range outside the loaded ones', () => {
		expect(missingYears('2029-05-01', '2029-05-10', new Set([2025, 2026, 2027]))).toEqual([2029])
		expect(missingYears('2023-12-27', '2024-01-05', new Set([2025, 2026, 2027]))).toEqual([2023, 2024])
	})

	it('asks only for the missing years of a range that is partly loaded', () => {
		expect(missingYears('2027-12-20', '2028-01-07', new Set([2025, 2026, 2027]))).toEqual([2028])
		expect(missingYears('2024-06-01', '2028-06-01', new Set([2025, 2026, 2027]))).toEqual([2024, 2028])
	})

	it('asks for nothing without a whole range', () => {
		expect(missingYears(null, '2029-05-10', new Set())).toEqual([])
		expect(missingYears('2029-05-01', null, new Set())).toEqual([])
		expect(missingYears('2029-05-10', '2029-05-01', new Set())).toEqual([])
	})
})
