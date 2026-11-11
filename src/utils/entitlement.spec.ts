import type { EntitlementForm } from './entitlement.ts'

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { entitlementPayload } from './entitlement.ts'

const form = (values: Partial<EntitlementForm>): EntitlementForm => ({ baseDays: '30', adjustmentDelta: '', adjustmentNote: '', ...values })

describe('entitlementPayload', () => {
	it('refuses an emptied base days field instead of reading it as zero', () => {
		expect(entitlementPayload(form({ baseDays: '' }), 30)).toBeNull()
		expect(entitlementPayload(form({ baseDays: '  ' }), 30)).toBeNull()
	})

	it('refuses base days that are not a number', () => {
		expect(entitlementPayload(form({ baseDays: 'abc' }), 30)).toBeNull()
	})

	it('leaves unchanged base days out, typed or untouched', () => {
		expect(entitlementPayload(form({ baseDays: '30' }), 30)).toEqual({ adjustmentNote: '' })
		expect(entitlementPayload(form({ baseDays: 30 }), 30)).toEqual({ adjustmentNote: '' })
	})

	it('sends changed base days, zero included', () => {
		expect(entitlementPayload(form({ baseDays: '28.5' }), 30)).toEqual({ adjustmentNote: '', baseDays: 28.5 })
		expect(entitlementPayload(form({ baseDays: '0' }), 30)).toEqual({ adjustmentNote: '', baseDays: 0 })
	})

	it('sends an adjustment on its own, without the base days', () => {
		expect(entitlementPayload(form({ adjustmentDelta: '-2', adjustmentNote: 'Unpaid days' }), 30))
			.toEqual({ adjustmentNote: 'Unpaid days', adjustmentDelta: -2 })
	})
})
