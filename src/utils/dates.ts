/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Small date helpers, including the working-day prefill calculation: count the
 * days in a range that fall on the user's working weekdays and are not public
 * holidays. The count only prefills the request form — it stays editable.
 */

/**
 * Format a Date as 'YYYY-MM-DD' in local time.
 *
 * @param date the date to format
 */
export function toIso(date: Date): string {
	const y = date.getFullYear()
	const m = String(date.getMonth() + 1).padStart(2, '0')
	const d = String(date.getDate()).padStart(2, '0')
	return `${y}-${m}-${d}`
}

/**
 * Parse a "1,2,3,4,5" weekday string into a Set of ISO weekdays (Mon=1..Sun=7).
 *
 * @param csv comma-separated ISO weekdays; empty or missing yields an empty set
 */
export function parseWeekdays(csv: string | null | undefined): Set<number> {
	const set = new Set<number>()
	for (const part of String(csv || '').split(',')) {
		const n = parseInt(part, 10)
		if (n >= 1 && n <= 7) {
			set.add(n)
		}
	}
	return set
}

/**
 * Count working days in the inclusive range [startIso, endIso]: days whose ISO
 * weekday is in `weekdays` and which `isHoliday(iso)` does not flag. Returns 0
 * for an empty/reversed range. Used to prefill the request form (§7 prefill).
 *
 * @param startIso 'YYYY-MM-DD'
 * @param endIso 'YYYY-MM-DD'
 * @param weekdays ISO weekdays that count (Mon=1..Sun=7)
 * @param isHoliday optional public-holiday predicate
 */
export function countWorkingDays(startIso: string, endIso: string, weekdays: Set<number>, isHoliday?: ((iso: string) => boolean) | null): number {
	if (!startIso || !endIso || !weekdays || weekdays.size === 0) {
		return 0
	}
	const end = new Date(endIso + 'T00:00:00')
	let count = 0
	for (const d = new Date(startIso + 'T00:00:00'); d <= end; d.setDate(d.getDate() + 1)) {
		const isoWeekday = d.getDay() === 0 ? 7 : d.getDay()
		if (weekdays.has(isoWeekday) && !(isHoliday && isHoliday(toIso(d)))) {
			count++
		}
	}
	return count
}

/**
 * Localised short date.
 *
 * @param iso 'YYYY-MM-DD'; empty or missing yields ''
 */
export function formatDate(iso: string | null | undefined): string {
	if (!iso) {
		return ''
	}
	return new Date(iso + 'T00:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

/**
 * Human range like "3 – 7 Mar 2026".
 *
 * @param startIso 'YYYY-MM-DD'
 * @param endIso 'YYYY-MM-DD'
 */
export function formatRange(startIso: string, endIso: string): string {
	if (startIso === endIso) {
		return formatDate(startIso)
	}
	return `${formatDate(startIso)} – ${formatDate(endIso)}`
}

/**
 * A duration as `H:MM:SS`, for the final stretch before a break starts. Hours are
 * not padded (and run past 24) because "47:59:12" reads as a countdown while
 * "01:23:45" reads as a clock. Never negative: a lapsed deadline shows 0:00:00.
 *
 * @param ms milliseconds remaining
 */
export function formatCountdown(ms: number): string {
	const total = Math.max(0, Math.floor(ms / 1000))
	const minutes = String(Math.floor((total % 3600) / 60)).padStart(2, '0')
	const seconds = String(total % 60).padStart(2, '0')
	return `${Math.floor(total / 3600)}:${minutes}:${seconds}`
}

export type Season = 'winter' | 'spring' | 'summer' | 'autumn'

/**
 * Countries whose seasons run opposite to the northern calendar. Not a complete
 * list of the southern hemisphere — tropical countries that straddle the equator
 * have no meaningful season flip, and guessing one for them would be worse than
 * leaving the illustration on its northern default.
 */
const SOUTHERN_SEASONS = new Set([
	'AR',
	'AU',
	'BO',
	'BR',
	'BW',
	'CL',
	'FJ',
	'LS',
	'MG',
	'MW',
	'MZ',
	'NA',
	'NZ',
	'PY',
	'SZ',
	'UY',
	'ZA',
	'ZM',
	'ZW',
])

/**
 * The meteorological season for a date, flipped for southern-hemisphere users so
 * the illustration is not showing snow to somebody in a Sydney January. The
 * country is the one already picked for public holidays — no new setting.
 *
 * @param date the day to place in a season
 * @param country ISO 3166-1 alpha-2, e.g. the holiday country
 */
export function seasonOf(date: Date, country?: string | null): Season {
	const month = date.getMonth()
	const northern: Season = (month === 11 || month <= 1)
		? 'winter'
		: month <= 4 ? 'spring' : month <= 7 ? 'summer' : 'autumn'
	if (!SOUTHERN_SEASONS.has(String(country || '').toUpperCase())) {
		return northern
	}
	const flipped: Record<Season, Season> = { winter: 'summer', spring: 'autumn', summer: 'winter', autumn: 'spring' }
	return flipped[northern]
}

/**
 * Spread a request's manually entered working-day count over the months of
 * `year`, proportionally to the calendar days it covers in each month. Adds
 * the result onto `buckets` (an array of 12 numbers, mutated in place).
 *
 * @param buckets twelve monthly totals, January first
 * @param startIso 'YYYY-MM-DD'
 * @param endIso 'YYYY-MM-DD'
 * @param workingDays the request's working-day count
 * @param year the year the buckets describe
 */
export function addWorkingDaysByMonth(buckets: number[], startIso: string, endIso: string, workingDays: number, year: number): void {
	const dayMs = 86400000
	const start = new Date(startIso + 'T00:00:00')
	const end = new Date(endIso + 'T00:00:00')
	const totalDays = Math.round((end.getTime() - start.getTime()) / dayMs) + 1
	if (totalDays <= 0 || !workingDays) {
		return
	}
	for (let month = 0; month < 12; month++) {
		const monthStart = new Date(year, month, 1)
		const monthEnd = new Date(year, month + 1, 0)
		const overlapStart = start > monthStart ? start : monthStart
		const overlapEnd = end < monthEnd ? end : monthEnd
		const overlap = Math.round((overlapEnd.getTime() - overlapStart.getTime()) / dayMs) + 1
		if (overlap > 0) {
			buckets[month] += workingDays * (overlap / totalDays)
		}
	}
}
