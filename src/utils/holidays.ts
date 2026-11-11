/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Public holidays come from the list HR maintains (§7.2), by region code:
 * a country (`DE`), a region (`DE-BY`) or `*` for everywhere.
 */

const CODE = /^([A-Z]{2})(?:[-_]([A-Z0-9]{1,3}))?$/

/** A region code split in its two parts, both '' for "no region" */
export interface RegionCode {
	country: string
	region: string
}

/** A day off, as far as these helpers look at it */
interface Dated {
	date: string
}

/**
 * `de-by` or `DE_BY` → { country: 'DE', region: 'BY' }. Null when it is not a code; an empty
 * code is valid and means "no region".
 *
 * @param code what the person typed
 */
export function parseRegionCode(code: string | null | undefined): RegionCode | null {
	const value = (code || '').trim().toUpperCase()
	if (value === '') {
		return { country: '', region: '' }
	}
	const [, country, region] = CODE.exec(value) ?? []
	return country ? { country, region: region || '' } : null
}

/**
 * @param country two-letter country
 * @param region subdivision, may be empty
 * @return `DE-BY`, `DE`, or '' when no country is set
 */
export function formatRegionCode(country: string, region: string): string {
	if (!country) {
		return ''
	}
	return region ? `${country}-${region}` : country
}

/**
 * Whether HR entered any holiday for this code or its country.
 *
 * @param code a valid, non-empty region code
 * @param regions the codes that have holidays
 */
export function hasHolidays(code: string, regions: string[]): boolean {
	const parsed = parseRegionCode(code)
	return parsed !== null && (regions.includes(formatRegionCode(parsed.country, parsed.region)) || regions.includes(parsed.country))
}

/**
 * What changes for someone moving from one region code to another.
 *
 * @param before holidays of the old code
 * @param after holidays of the new code
 */
export function compareHolidays<T extends Dated>(before: T[], after: T[]): { lost: T[], gained: T[], kept: number } {
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
 * @param holidays the days off
 */
export function makeHolidayChecker(holidays: Dated[]): (iso: string) => boolean {
	const dates = new Set(holidays.map((h) => h.date))
	return (iso) => dates.has(iso)
}

/**
 * Days grouped by their year, in date order, for a list split under year headings.
 *
 * @param days the days to group
 */
export function groupByYear<T extends Dated>(days: T[]): { year: string, days: T[] }[] {
	const years = new Map<string, T[]>()
	for (const day of [...days].sort((a, b) => a.date.localeCompare(b.date))) {
		const year = day.date.slice(0, 4)
		years.set(year, [...(years.get(year) ?? []), day])
	}
	return [...years].map(([year, list]) => ({ year, days: list }))
}
