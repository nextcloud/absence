/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { celebratedIds } from './celebrations.ts'

describe('celebratedIds', () => {
	const today = '2026-11-18'

	it('keeps the approvals still to come or under way', () => {
		expect(celebratedIds([
			{ id: 1, status: 'APPROVED', endDate: '2026-12-24' },
			{ id: 2, status: 'APPROVED', endDate: today },
		], today)).toEqual([1, 2])
	})

	it('drops the approvals that have ended', () => {
		expect(celebratedIds([
			{ id: 1, status: 'APPROVED', endDate: '2026-11-17' },
			{ id: 2, status: 'APPROVED', endDate: '2026-12-24' },
		], today)).toEqual([2])
	})

	it('drops what is not approved, or no longer', () => {
		expect(celebratedIds([
			{ id: 1, status: 'PENDING', endDate: '2026-12-24' },
			{ id: 2, status: 'CANCELLED', endDate: '2026-12-24' },
			{ id: 3, status: 'WITHDRAWAL_PENDING', endDate: '2026-12-24' },
		], today)).toEqual([])
	})
})
