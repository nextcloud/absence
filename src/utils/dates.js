/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Small date helpers. Working-day counts are entered manually by the employee (§7),
 * so there is no client-side working-day calculation here.
 */

/** Format a Date as 'YYYY-MM-DD' in local time. */
export function toIso(date) {
	const y = date.getFullYear()
	const m = String(date.getMonth() + 1).padStart(2, '0')
	const d = String(date.getDate()).padStart(2, '0')
	return `${y}-${m}-${d}`
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
