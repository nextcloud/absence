/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { t } from '@nextcloud/l10n'
import { formatWeekdays } from './weekdays.js'

/**
 * A working pattern the short way: "Mon–Thu, 80%".
 *
 * @param {{weekdays: number[], percent: number|null}} period a contract period
 * @return {string}
 */
export function describePeriod(period) {
	const days = formatWeekdays(period.weekdays)
	return period.percent === null ? days : t('absence', '{days}, {percent}%', { days, percent: period.percent })
}

const same = (a, b) => a.from === b.from && a.percent === b.percent && a.weekdays.join() === b.weekdays.join()

/**
 * What a new contract changes: the periods it drops and the ones it adds. When
 * it only adds, `kept` is the period in force before them, for context.
 *
 * @param {object[]} before the stored periods, by date
 * @param {object[]} after the new periods, by date
 * @return {{removed: object[], added: object[], kept: object[]}}
 */
export function contractDiff(before, after) {
	const removed = before.filter((period) => !after.some((other) => same(period, other)))
	const added = after.filter((period) => !before.some((other) => same(period, other)))
	return { removed, added, kept: removed.length ? [] : before.slice(-1) }
}
