/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Public holidays come from the list HR maintains (§7.2), by region code:
 * a country (`DE`), a region (`DE-BY`) or `*` for everywhere.
 */

const CODE = /^([A-Z]{2})(?:[-_]([A-Z0-9]{1,3}))?$/

/**
 * `de-by` or `DE_BY` → { country: 'DE', region: 'BY' }. Null when it is not a code; an empty
 * code is valid and means "no region".
 *
 * @param {string} code what the person typed
 * @return {{country: string, region: string} | null}
 */
export function parseRegionCode(code) {
	const value = (code || '').trim().toUpperCase()
	if (value === '') {
		return { country: '', region: '' }
	}
	const match = CODE.exec(value)
	return match ? { country: match[1], region: match[2] || '' } : null
}

/**
 * @param {string} country two-letter country
 * @param {string} region subdivision, may be empty
 * @return {string} `DE-BY`, `DE`, or '' when no country is set
 */
export function formatRegionCode(country, region) {
	if (!country) {
		return ''
	}
	return region ? `${country}-${region}` : country
}

/**
 * Whether HR entered any holiday for this code or its country.
 *
 * @param {string} code a valid, non-empty region code
 * @param {string[]} regions the codes that have holidays
 * @return {boolean}
 */
export function hasHolidays(code, regions) {
	const parsed = parseRegionCode(code)
	return parsed !== null && (regions.includes(formatRegionCode(parsed.country, parsed.region)) || regions.includes(parsed.country))
}

/**
 * What changes for someone moving from one region code to another.
 *
 * @param {Array<{date: string, label: string}>} before holidays of the old code
 * @param {Array<{date: string, label: string}>} after holidays of the new code
 * @return {{lost: Array<{date: string, label: string}>, gained: Array<{date: string, label: string}>, kept: number}}
 */
export function compareHolidays(before, after) {
	const beforeDates = new Set(before.map((h) => h.date))
	const afterDates = new Set(after.map((h) => h.date))
	return {
		lost: before.filter((h) => !afterDates.has(h.date)),
		gained: after.filter((h) => !beforeDates.has(h.date)),
		kept: after.filter((h) => beforeDates.has(h.date)).length,
	}
}

/**
 * A predicate `(iso) => boolean` for the working-days prefill.
 *
 * @param {Array<{date: string}>} holidays the days off
 * @return {(iso: string) => boolean}
 */
export function makeHolidayChecker(holidays) {
	const dates = new Set(holidays.map((h) => h.date))
	return (iso) => dates.has(iso)
}

/**
 * Days grouped by their year, in date order, for a list split under year headings.
 *
 * @param {Array<{date: string}>} days the days to group
 * @return {Array<{year: string, days: Array<{date: string}>}>}
 */
export function groupByYear(days) {
	const years = new Map()
	for (const day of [...days].sort((a, b) => a.date.localeCompare(b.date))) {
		const year = day.date.slice(0, 4)
		years.set(year, [...(years.get(year) ?? []), day])
	}
	return [...years].map(([year, list]) => ({ year, days: list }))
}
