/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { readableTextColor } from './colors.ts'

describe('readableTextColor', () => {
	it('puts white on dark colours', () => {
		expect(readableTextColor('#1b5e20')).toBe('#fff')
		expect(readableTextColor('#000000')).toBe('#fff')
		expect(readableTextColor('#7b1fa2')).toBe('#fff')
	})

	it('puts black on light colours', () => {
		expect(readableTextColor('#ffeb3b')).toBe('#000')
		expect(readableTextColor('#FFFFFF')).toBe('#000')
		expect(readableTextColor('#a5d6a7')).toBe('#000')
	})

	it('reads the short form like the long one', () => {
		expect(readableTextColor('#ff0')).toBe(readableTextColor('#ffff00'))
		expect(readableTextColor('#888')).toBe(readableTextColor('#888888'))
	})

	it('stays white for something that is not a hex colour', () => {
		expect(readableTextColor('var(--color-primary)')).toBe('#fff')
		expect(readableTextColor(undefined)).toBe('#fff')
	})
})
