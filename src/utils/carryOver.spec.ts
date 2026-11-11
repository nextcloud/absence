/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	getCanonicalLocale: () => 'en-GB',
	n: (_app: string, singular: string, plural: string, count: number, vars: Record<string, string>) => (count === 1 ? singular : plural)
		.replace(/\{(\w+)\}/g, (_match: string, key: string) => vars[key] ?? ''),
}))

const { carryOverExpiryNotice } = await import('./carryOver.ts')

describe('carryOverExpiryNotice', () => {
	it('says how many days expire and when, in the account locale', () => {
		expect(carryOverExpiryNotice({ carryOverExpiring: 3, carryOverExpiryDate: '2032-03-31' }))
			.toBe('3 carried-over days expire on 31 Mar 2032')
	})

	it('uses the singular for one day and for half a day', () => {
		expect(carryOverExpiryNotice({ carryOverExpiring: 1, carryOverExpiryDate: '2032-03-31' }))
			.toBe('1 carried-over day expires on 31 Mar 2032')
		expect(carryOverExpiryNotice({ carryOverExpiring: 0.5, carryOverExpiryDate: '2032-03-31' }))
			.toBe('0.5 carried-over day expires on 31 Mar 2032')
	})

	it('uses the plural for one and a half days', () => {
		expect(carryOverExpiryNotice({ carryOverExpiring: 1.5, carryOverExpiryDate: '2032-03-31' }))
			.toBe('1.5 carried-over days expire on 31 Mar 2032')
	})

	it('says nothing when no days are at risk or no date is set', () => {
		expect(carryOverExpiryNotice({ carryOverExpiring: 0, carryOverExpiryDate: null })).toBe('')
		expect(carryOverExpiryNotice({ carryOverExpiring: 0, carryOverExpiryDate: '2032-03-31' })).toBe('')
		expect(carryOverExpiryNotice({ carryOverExpiring: 2, carryOverExpiryDate: null })).toBe('')
	})
})
