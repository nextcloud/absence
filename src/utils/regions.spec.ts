/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'
import { filterRegionPickerItems, holidayAppliesTo, holidaySources, regionCountryName, regionFlag, regionName, regionPickerItems } from './regions.ts'

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

describe('regionName', () => {
	it('names countries and German states, and keeps other codes as they are', () => {
		expect(regionName('FR')).toBe('France')
		expect(regionName('DE-BY')).toBe('Bavaria')
		expect(regionName('ES-CT')).toBe('ES-CT')
		expect(regionName('*')).toBe('*')
	})
})

describe('regionPickerItems', () => {
	const labels = (codes: string[], current = '') => regionPickerItems(codes, current).map((item) => (item.heading ? `# ${item.label}` : item.code))

	it('lists the countries by name, then the regions of each country under a heading', () => {
		expect(labels(['*', 'GB', 'DE-BY', 'FR', 'DE', 'DE-BE', 'AT'])).toEqual([
			'# Countries',
			'AT',
			'FR',
			'DE',
			'GB',
			'# Germany, by region',
			'DE-BY',
			'DE-BE',
		])
	})

	it('lists the person\'s own code even when it has no holidays', () => {
		expect(labels(['FR'], 'it')).toEqual(['# Countries', 'FR', 'IT'])
		expect(labels(['FR'], '')).toEqual(['# Countries', 'FR'])
	})
})

describe('filterRegionPickerItems', () => {
	const items = regionPickerItems(['FR', 'DE', 'DE-BY', 'DE-BW'], '')
	const codes = (search: string) => filterRegionPickerItems(items, search).map((item) => (item.heading ? `# ${item.label}` : item.code))

	it('finds an entry by its name, its country or its code, accents and case aside', () => {
		expect(codes('bav')).toEqual(['# Germany, by region', 'DE-BY'])
		expect(codes('de-bw')).toEqual(['# Germany, by region', 'DE-BW'])
		expect(codes('wurttemberg')).toEqual(['# Germany, by region', 'DE-BW'])
		expect(codes('germ')).toEqual(['# Countries', 'DE', '# Germany, by region', 'DE-BW', 'DE-BY'])
	})

	it('keeps everything for an empty search and nothing without a match', () => {
		expect(filterRegionPickerItems(items, ' ')).toBe(items)
		expect(codes('zz')).toEqual([])
	})
})
