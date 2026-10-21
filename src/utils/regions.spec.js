/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { regionFlag } from './regions.js'

describe('regionFlag', () => {
	it('shows the country of a code', () => {
		expect(regionFlag('DE')).toBe('🇩🇪')
		expect(regionFlag('DE-BY')).toBe('🇩🇪')
		expect(regionFlag('fr')).toBe('🇫🇷')
	})

	it('has a globe for everywhere and nothing for a code without a country', () => {
		expect(regionFlag('*')).toBe('🌍')
		expect(regionFlag('default')).toBe('')
	})
})
