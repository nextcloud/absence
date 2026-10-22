/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getLanguage } from '@nextcloud/l10n'

/** Holidays entered for this code apply to everyone (§7.2) */
export const EVERYWHERE = '*'

/**
 * The flag of a region code's country: 🇩🇪 for `DE` and `DE-BY`, 🌍 for everywhere.
 *
 * @param {string} code a region code
 * @return {string} the emoji, or '' when the code has no country
 */
export function regionFlag(code) {
	if (code === EVERYWHERE) {
		return '🌍'
	}
	const match = /^([a-z]{2})(?:[-_]|$)/i.exec(code || '')
	if (!match) {
		return ''
	}
	return [...match[1].toUpperCase()].map((letter) => String.fromCodePoint(0x1F1E6 + letter.charCodeAt(0) - 65)).join('')
}

/**
 * The country name of a region code in the user's language: "Germany" for `DE-BY`.
 *
 * @param {string} code a region code
 * @return {string} the name, or '' when the code has no country
 */
export function regionCountryName(code) {
	const match = /^([a-z]{2})(?:[-_]|$)/i.exec(code || '')
	if (!match) {
		return ''
	}
	const name = new Intl.DisplayNames([getLanguage().replace('_', '-'), 'en'], { type: 'region', fallback: 'none' }).of(match[1].toUpperCase())
	return name ?? ''
}
