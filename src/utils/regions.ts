/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getLanguage, t } from '@nextcloud/l10n'

/** Holidays entered for this code apply to everyone (§7.2) */
export const EVERYWHERE = '*'

/**
 * The two-letter country a region code starts with, upper case.
 *
 * @param code a region code
 * @return null when the code has no country
 */
function countryOf(code: string | null | undefined): string | null {
	return /^([a-z]{2})(?:[-_]|$)/i.exec(code || '')?.[1]?.toUpperCase() ?? null
}

/**
 * The flag of a region code's country: 🇩🇪 for `DE` and `DE-BY`, 🌍 for everywhere.
 *
 * @param code a region code
 * @return the emoji, or '' when the code has no country
 */
export function regionFlag(code: string | null | undefined): string {
	if (code === EVERYWHERE) {
		return '🌍'
	}
	const country = countryOf(code)
	if (!country) {
		return ''
	}
	return [...country].map((letter) => String.fromCodePoint(0x1F1E6 + letter.charCodeAt(0) - 65)).join('')
}

/**
 * The country name of a region code in the user's language: "Germany" for `DE-BY`.
 *
 * @param code a region code
 * @return the name, or '' when the code has no country
 */
export function regionCountryName(code: string | null | undefined): string {
	const country = countryOf(code)
	if (!country) {
		return ''
	}
	const name = new Intl.DisplayNames([getLanguage().replace('_', '-'), 'en'], { type: 'region', fallback: 'none' }).of(country)
	return name ?? ''
}

/**
 * Whether a holiday entered for `region` applies to someone with `code` (§7.2):
 * the days of the code, of its country and of everywhere.
 *
 * @param region the region code of the holiday
 * @param code the person's region code, uppercase such as `DE-BY`
 */
export function holidayAppliesTo(region: string, code: string): boolean {
	return region === EVERYWHERE || region === code || region === countryOf(code)
}

/**
 * Where the holidays of someone with this region code come from, in one sentence.
 *
 * @param code their region code, '' when they set none
 * @return '' without a code
 */
export function holidaySources(code: string): string {
	const country = countryOf(code)
	if (!country) {
		return ''
	}
	const countryName = regionCountryName(code) || country
	return code === country
		? t('absence', 'You get the holidays of {country} and those for everyone.', { country: countryName }, undefined, { escape: false, sanitize: false })
		: t('absence', 'You get the holidays of {region}, of {country} and those for everyone.', { region: code, country: countryName }, undefined, { escape: false, sanitize: false })
}

/** One entry of the region picker: a group heading or a region code */
export interface RegionPickerItem {
	/** The region code, or `group:<name>` for a heading */
	id: string
	heading: boolean
	/** The heading, or the name of the country or region */
	label: string
	/** The region code, '' for a heading */
	code: string
	/** The country of a region, '' for a country or a heading */
	country: string
	flag: string
}

/**
 * The name of a German state, which browsers cannot name.
 *
 * @param region the part after `DE-`
 */
function germanStateName(region: string): string {
	const names: Record<string, string> = {
		BB: t('absence', 'Brandenburg'),
		BE: t('absence', 'Berlin'),
		BW: t('absence', 'Baden-Württemberg'),
		BY: t('absence', 'Bavaria'),
		HB: t('absence', 'Bremen'),
		HE: t('absence', 'Hesse'),
		HH: t('absence', 'Hamburg'),
		MV: t('absence', 'Mecklenburg-Western Pomerania'),
		NI: t('absence', 'Lower Saxony'),
		NW: t('absence', 'North Rhine-Westphalia'),
		RP: t('absence', 'Rhineland-Palatinate'),
		SH: t('absence', 'Schleswig-Holstein'),
		SL: t('absence', 'Saarland'),
		SN: t('absence', 'Saxony'),
		ST: t('absence', 'Saxony-Anhalt'),
		TH: t('absence', 'Thuringia'),
	}
	return names[region] ?? ''
}

/**
 * The name of a region code in the user's language: "France" for `FR`,
 * "Bavaria" for `DE-BY`, the code itself when it has no known name.
 *
 * @param code a region code
 */
export function regionName(code: string): string {
	const country = countryOf(code)
	if (!country) {
		return code
	}
	const region = code.slice(3).toUpperCase()
	if (region === '') {
		return regionCountryName(code) || code
	}
	return (country === 'DE' && germanStateName(region)) || code
}

/**
 * The entries of the region picker: the countries, then the regions of each
 * country under a heading, each sorted by name in the user's language.
 *
 * @param codes the region codes that have holidays
 * @param current the person's code, listed even without holidays, '' for none
 */
export function regionPickerItems(codes: string[], current: string): RegionPickerItem[] {
	const collator = new Intl.Collator(getLanguage().replace('_', '-'))
	const all = new Set(codes.filter((code) => countryOf(code) !== null).map((code) => code.toUpperCase()))
	if (countryOf(current)) {
		all.add(current.toUpperCase())
	}
	const item = (code: string): RegionPickerItem => ({
		id: code,
		heading: false,
		label: regionName(code),
		code,
		country: code.length > 2 ? regionCountryName(code) : '',
		flag: regionFlag(code),
	})
	const byName = (a: RegionPickerItem, b: RegionPickerItem) => collator.compare(a.label, b.label)

	const countries = [...all].filter((code) => code.length === 2).map(item).sort(byName)
	const regions = new Map<string, RegionPickerItem[]>()
	for (const code of [...all].filter((code) => code.length > 2)) {
		const entry = item(code)
		const name = entry.country || code.slice(0, 2)
		regions.set(name, [...(regions.get(name) ?? []), entry])
	}

	const items: RegionPickerItem[] = []
	const heading = (label: string) => items.push({ id: `group:${label}`, heading: true, label, code: '', country: '', flag: '' })
	if (countries.length) {
		heading(t('absence', 'Countries'))
		items.push(...countries)
	}
	for (const country of [...regions.keys()].sort(collator.compare)) {
		heading(t('absence', '{country}, by region', { country }, undefined, { escape: false, sanitize: false }))
		items.push(...(regions.get(country) ?? []).sort(byName))
	}
	return items
}

/**
 * Keeps the entries whose name, country or code contain the search, accents
 * and case aside, with the headings of the groups that keep an entry.
 *
 * @param items the entries of the picker
 * @param search what the person typed
 */
export function filterRegionPickerItems(items: RegionPickerItem[], search: string): RegionPickerItem[] {
	const fold = (text: string) => text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase()
	const query = fold(search.trim())
	if (query === '') {
		return items
	}
	const kept: RegionPickerItem[] = []
	let pendingHeading: RegionPickerItem | null = null
	for (const entry of items) {
		if (entry.heading) {
			pendingHeading = entry
		} else if ([entry.label, entry.country, entry.code].some((text) => fold(text).includes(query))) {
			if (pendingHeading) {
				kept.push(pendingHeading)
				pendingHeading = null
			}
			kept.push(entry)
		}
	}
	return kept
}
