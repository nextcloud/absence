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
