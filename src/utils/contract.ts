/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { t } from '@nextcloud/l10n'
import { formatWeekdays } from './weekdays.ts'

/** What two contract periods are compared by */
export interface PeriodPattern {
	from: string
	weekdays: number[]
	percent: number | null
}

/**
 * A working pattern the short way: "Mon–Thu, 80%".
 *
 * @param period a contract period
 */
export function describePeriod(period: Pick<PeriodPattern, 'weekdays' | 'percent'>): string {
	const days = formatWeekdays(period.weekdays)
	return period.percent === null ? days : t('absence', '{days}, {percent}%', { days, percent: period.percent })
}

/**
 * The weekdays someone works on a day, or null when HR entered none for it.
 *
 * @param periods the contract periods, by date
 * @param iso 'YYYY-MM-DD'
 * @return ISO weekdays, Mon=1
 */
export function weekdaysOn(periods: Pick<PeriodPattern, 'from' | 'weekdays'>[] | null | undefined, iso: string | null): Set<number> | null {
	const period = iso === null ? undefined : [...(periods ?? [])].reverse().find((candidate) => candidate.from <= iso)
	return period ? new Set(period.weekdays) : null
}

/**
 * @param a one period
 * @param b another period
 */
function same(a: PeriodPattern, b: PeriodPattern): boolean {
	return a.from === b.from && a.percent === b.percent && a.weekdays.join() === b.weekdays.join()
}

/**
 * What a new contract changes: the periods it drops and the ones it adds. When
 * it only adds, `kept` is the period in force before them, for context.
 *
 * @param before the stored periods, by date
 * @param after the new periods, by date
 */
export function contractDiff<T extends PeriodPattern>(before: T[], after: T[]): { removed: T[], added: T[], kept: T[] } {
	const removed = before.filter((period) => !after.some((other) => same(period, other)))
	const added = after.filter((period) => !before.some((other) => same(period, other)))
	return { removed, added, kept: removed.length ? [] : before.slice(-1) }
}
