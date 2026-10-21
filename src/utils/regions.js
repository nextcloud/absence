/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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
