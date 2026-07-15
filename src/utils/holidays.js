/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Thin wrapper around `date-holidays`, which carries the public-holiday data for
 * all countries and sub-regions (incl. German Bundesländer). It is heavy, so it
 * is dynamically imported only when a holiday region is configured — the main
 * bundle never pulls it in.
 */

/**
 * Build a predicate `(iso) => boolean` that is true on public holidays for the
 * given country/region. Returns null when no country is set (no subtraction).
 *
 * @param {string} country ISO country code, e.g. 'DE'
 * @param {string} [region] sub-region/state code, e.g. 'BY'
 * @return {Promise<((iso: string) => boolean) | null>}
 */
export async function makeHolidayChecker(country, region) {
	if (!country) {
		return null
	}
	const { default: Holidays } = await import('date-holidays')
	const hd = region ? new Holidays(country, region) : new Holidays(country)
	return (iso) => {
		// Midday avoids any DST/timezone edge landing on the wrong day.
		const res = hd.isHoliday(new Date(iso + 'T12:00:00'))
		// isHoliday returns false or an array of matches; count only real public
		// holidays (not observances, optional or school days).
		return Array.isArray(res) && res.some((h) => h.type === 'public')
	}
}

/**
 * List of { id, label } countries for the settings dropdown. Lazily imported.
 *
 * @return {Promise<Array<{id: string, label: string}>>}
 */
export async function listCountries() {
	const { default: Holidays } = await import('date-holidays')
	const map = new Holidays().getCountries()
	return Object.keys(map).map((id) => ({ id, label: map[id] }))
}

/**
 * List of { id, label } states/regions for a country (empty if the country has
 * none). Lazily imported.
 *
 * @param {string} country
 * @return {Promise<Array<{id: string, label: string}>>}
 */
export async function listRegions(country) {
	if (!country) {
		return []
	}
	const { default: Holidays } = await import('date-holidays')
	const map = new Holidays().getStates(country) || {}
	return Object.keys(map).map((id) => ({ id, label: map[id] }))
}
