/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'
import { holidayAppliesTo, holidaySources, regionCountryName, regionFlag } from './regions.ts'

vi.mock('@nextcloud/l10n', () => ({
	getLanguage: () => 'en',
	t: (_app: string, text: string, vars: Record<string, string>) => text.replace(/\{(\w+)\}/g, (_match: string, key: string) => vars[key] ?? ''),
}))

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

describe('holidayAppliesTo', () => {
	it('takes the days of the code, of its country and of everywhere', () => {
		expect(holidayAppliesTo('DE-BY', 'DE-BY')).toBe(true)
		expect(holidayAppliesTo('DE', 'DE-BY')).toBe(true)
		expect(holidayAppliesTo('*', 'DE-BY')).toBe(true)
	})

	it('leaves out other regions, even of the same country', () => {
		expect(holidayAppliesTo('DE-BE', 'DE-BY')).toBe(false)
		expect(holidayAppliesTo('FR', 'DE-BY')).toBe(false)
		expect(holidayAppliesTo('DE-BY', 'DE')).toBe(false)
	})
})

describe('holidaySources', () => {
	it('names the region and its country', () => {
		expect(holidaySources('DE-BY')).toBe('You get the holidays of DE-BY, of Germany and those for everyone.')
	})

	it('names only the country for a code without a region', () => {
		expect(holidaySources('FR')).toBe('You get the holidays of France and those for everyone.')
	})

	it('says nothing without a code', () => {
		expect(holidaySources('')).toBe('')
	})
})
