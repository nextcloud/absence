/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { formatHours } from './duration.ts'

describe('formatHours', () => {
	it('says under an hour below one hour', () => {
		expect(formatHours(0)).toBe('under an hour')
		expect(formatHours(0.9)).toBe('under an hour')
	})

	it('counts whole hours below two days', () => {
		expect(formatHours(1)).toBe('1 hour')
		expect(formatHours(5.4)).toBe('5 hours')
		expect(formatHours(47.9)).toBe('48 hours')
	})

	it('counts days from two days on', () => {
		expect(formatHours(48)).toBe('2 days')
		expect(formatHours(60)).toBe('2.5 days')
	})

	it('shows a dash when there is nothing to measure', () => {
		expect(formatHours(null)).toBe('–')
	})
})
