/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getLanguage } from '@nextcloud/l10n'

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
