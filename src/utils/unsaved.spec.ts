/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { keepUnsaved } from './unsaved.ts'

describe('keepUnsaved', () => {
	const before = { default_entitlement: 25, handbook_url: '', caldav_shared: false }

	it('keeps what was typed in a field that this save did not send', () => {
		const form = { default_entitlement: 25, handbook_url: 'https://example.org/handbook', caldav_shared: true }
		const after = { ...before, caldav_shared: true }
		expect(keepUnsaved(form, before, after, ['caldav_shared'])).toEqual({
			default_entitlement: 25,
			handbook_url: 'https://example.org/handbook',
			caldav_shared: true,
		})
	})

	it('takes the server value for the saved field, even when the server adjusted it', () => {
		const form = { ...before, handbook_url: ' https://example.org/handbook ' }
		const after = { ...before, handbook_url: 'https://example.org/handbook' }
		expect(keepUnsaved(form, before, after, ['handbook_url']).handbook_url).toBe('https://example.org/handbook')
	})

	it('takes the server value for untouched fields that changed on the server', () => {
		const after = { ...before, default_entitlement: 30, caldav_shared: true }
		expect(keepUnsaved({ ...before, caldav_shared: true }, before, after, ['caldav_shared']).default_entitlement).toBe(30)
	})
})
