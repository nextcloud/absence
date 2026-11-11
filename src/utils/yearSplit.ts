/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Leave across New Year: a request books all its working days to the year it
 * starts in (§7), so the request form offers to cut it at 31 December.
 */

/** An inclusive range of days */
export interface DateRange {
	/** 'YYYY-MM-DD' */
	startIso: string
	/** 'YYYY-MM-DD' */
	endIso: string
}

/** A range cut at the end of the year it starts in */
export interface YearSplit {
	/** 31 December of the start year, the last day of the first part */
	firstEnd: string
	/** From 1 January to the original end, which can still cross a later New Year */
	rest: DateRange
}

/**
 * Whether a range starts in one year and ends in a later one. Not while the
 * range is incomplete or ends before it starts.
 *
 * @param startIso 'YYYY-MM-DD'
 * @param endIso 'YYYY-MM-DD'
 */
export function crossesYear(startIso: string | null, endIso: string | null): boolean {
	return startIso !== null && endIso !== null && startIso.slice(0, 4) < endIso.slice(0, 4)
}

/**
 * Cut a range at the end of the year it starts in. Only the first year is
 * split off: the rest is one range, however many years it runs over.
 *
 * @param startIso 'YYYY-MM-DD'
 * @param endIso 'YYYY-MM-DD'
 * @return the cut, null when the range stays within one year
 */
export function splitAtYearEnd(startIso: string | null, endIso: string | null): YearSplit | null {
	if (startIso === null || endIso === null || !crossesYear(startIso, endIso)) {
		return null
	}
	const year = Number(startIso.slice(0, 4))
	return {
		firstEnd: `${year}-12-31`,
		rest: { startIso: `${year + 1}-01-01`, endIso },
	}
}

/**
 * The range still to request once the picked one is saved: the rest of a
 * split, as long as the picked range is still its first part. Moving the end
 * off 31 December, or the start into another year, drops the split.
 *
 * @param split the cut made earlier, null when there was none
 * @param startIso 'YYYY-MM-DD', the start picked now
 * @param endIso 'YYYY-MM-DD', the end picked now
 */
export function restAfter(split: YearSplit | null, startIso: string | null, endIso: string | null): DateRange | null {
	if (split === null || startIso === null || endIso !== split.firstEnd || startIso.slice(0, 4) !== endIso.slice(0, 4)) {
		return null
	}
	return split.rest
}
