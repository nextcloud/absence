/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { bradfordLevel } from './bradford.ts'

describe('bradfordLevel', () => {
	it('stays low under the review threshold', () => {
		expect(bradfordLevel(0)).toBe('low')
		expect(bradfordLevel(2)).toBe('low')
		expect(bradfordLevel(499)).toBe('low')
	})

	it('asks for a review from 500', () => {
		expect(bradfordLevel(500)).toBe('review')
		expect(bradfordLevel(899)).toBe('review')
	})

	it('is serious from 900', () => {
		expect(bradfordLevel(900)).toBe('serious')
		expect(bradfordLevel(5000)).toBe('serious')
	})
})
