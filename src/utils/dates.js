/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Small date helpers, including the working-day prefill calculation: count the
 * days in a range that fall on the user's working weekdays and are not public
 * holidays. The count only prefills the request form — it stays editable.
 */

/** Format a Date as 'YYYY-MM-DD' in local time. */
export function toIso(date) {
	const y = date.getFullYear()
	const m = String(date.getMonth() + 1).padStart(2, '0')
	const d = String(date.getDate()).padStart(2, '0')
	return `${y}-${m}-${d}`
}

/** Parse a "1,2,3,4,5" weekday string into a Set of ISO weekdays (Mon=1..Sun=7). */
export function parseWeekdays(csv) {
	const set = new Set()
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
 * @param {string} startIso 'YYYY-MM-DD'
 * @param {string} endIso 'YYYY-MM-DD'
 * @param {Set<number>} weekdays ISO weekdays that count (Mon=1..Sun=7)
 * @param {(iso: string) => boolean} [isHoliday] optional public-holiday predicate
 * @return {number}
 */
export function countWorkingDays(startIso, endIso, weekdays, isHoliday) {
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

/** Localised short date. */
export function formatDate(iso) {
	if (!iso) {
		return ''
	}
	return new Date(iso + 'T00:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

/** Human range like "3 – 7 Mar 2026". */
export function formatRange(startIso, endIso) {
	if (startIso === endIso) {
		return formatDate(startIso)
	}
	return `${formatDate(startIso)} – ${formatDate(endIso)}`
}

/**
 * Spread a request's manually entered working-day count over the months of
 * `year`, proportionally to the calendar days it covers in each month. Adds
 * the result onto `buckets` (an array of 12 numbers, mutated in place).
 */
export function addWorkingDaysByMonth(buckets, startIso, endIso, workingDays, year) {
	const dayMs = 86400000
	const start = new Date(startIso + 'T00:00:00')
	const end = new Date(endIso + 'T00:00:00')
	const totalDays = Math.round((end - start) / dayMs) + 1
	if (totalDays <= 0 || !workingDays) {
		return
	}
	for (let month = 0; month < 12; month++) {
		const monthStart = new Date(year, month, 1)
		const monthEnd = new Date(year, month + 1, 0)
		const overlapStart = start > monthStart ? start : monthStart
		const overlapEnd = end < monthEnd ? end : monthEnd
		const overlap = Math.round((overlapEnd - overlapStart) / dayMs) + 1
		if (overlap > 0) {
			buckets[month] += workingDays * (overlap / totalDays)
		}
	}
}
