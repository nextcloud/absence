/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { labelStep } from './chartLabels.ts'

const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

describe('labelStep', () => {
	it('labels every month when there is room', () => {
		expect(labelStep(months, 50)).toBe(1)
	})

	it('skips labels that would run into each other', () => {
		expect(labelStep(months, 20)).toBe(2)
		expect(labelStep(months, 12)).toBe(3)
	})

	it('makes room for longer labels', () => {
		expect(labelStep(['2024', '2025', '2026'], 30)).toBe(2)
		expect(labelStep(['sept.', 'oct.'], 30)).toBe(2)
	})

	it('labels a single point', () => {
		expect(labelStep(['Jan'], 0)).toBe(1)
	})
})
