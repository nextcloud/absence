/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'
import { regionCountryName, regionFlag } from './regions.js'

vi.mock('@nextcloud/l10n', () => ({ getLanguage: () => 'en' }))

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

describe('regionCountryName', () => {
	it('names the country of a code', () => {
		expect(regionCountryName('DE-BY')).toBe('Germany')
		expect(regionCountryName('fr')).toBe('France')
	})

	it('has no name for everywhere or an unknown country', () => {
		expect(regionCountryName('*')).toBe('')
		expect(regionCountryName('QQ')).toBe('')
	})
})
