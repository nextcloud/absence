/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { canOpenRoute } from './access.ts'

describe('canOpenRoute', () => {
	it('opens ordinary pages to everyone', () => {
		expect(canOpenRoute({}, { isHr: false, isAdmin: false })).toBe(true)
	})

	it('keeps HR pages for HR', () => {
		expect(canOpenRoute({ hr: true }, { isHr: true })).toBe(true)
		expect(canOpenRoute({ hr: true }, { isHr: false })).toBe(false)
		expect(canOpenRoute({ hr: true }, { uid: null })).toBe(false)
	})

	it('lets an admin who is not HR into the settings, and nowhere else in HR', () => {
		const admin = { isHr: false, isAdmin: true }
		expect(canOpenRoute({ hr: true, admin: true }, admin)).toBe(true)
		expect(canOpenRoute({ hr: true }, admin)).toBe(false)
	})
})
