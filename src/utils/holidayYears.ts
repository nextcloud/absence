/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The years of a date range whose public holidays are still to fetch, in
 * order. None while the range is incomplete or ends before it starts.
 *
 * @param startIso 'YYYY-MM-DD'
 * @param endIso 'YYYY-MM-DD'
 * @param loaded the years already fetched, or being fetched
 */
export function missingYears(startIso: string | null, endIso: string | null, loaded: ReadonlySet<number>): number[] {
	if (!startIso || !endIso || endIso < startIso) {
		return []
	}
	const last = Number(endIso.slice(0, 4))
	const years: number[] = []
	for (let year = Number(startIso.slice(0, 4)); year <= last; year++) {
		if (!loaded.has(year)) {
			years.push(year)
		}
	}
	return years
}
