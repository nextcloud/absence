/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Whether a number field holds a number. A field of type number hands back
 * NaN once emptied, and a string where the model started as one.
 *
 * @param value what the field holds
 */
export function isNumeric(value: string | number): boolean {
	if (typeof value === 'number') {
		return Number.isFinite(value)
	}
	return value.trim() !== '' && Number.isFinite(Number(value))
}

/**
 * The year a URL query names, or the fallback when it names none: a query
 * value is whatever was typed in the address bar, or a list when repeated.
 *
 * @param value the query value
 * @param fallback the year to use instead
 */
export function yearFromQuery(value: unknown, fallback: number): number {
	return typeof value === 'string' && /^\d{4}$/.test(value) ? Number(value) : fallback
}
