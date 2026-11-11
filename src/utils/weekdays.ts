/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { t } from '@nextcloud/l10n'

/**
 * Short day names, Monday first, for ISO weekdays 1 to 7.
 */
export function dayNames(): string[] {
	return [t('absence', 'Mon'), t('absence', 'Tue'), t('absence', 'Wed'), t('absence', 'Thu'), t('absence', 'Fri'), t('absence', 'Sat'), t('absence', 'Sun')]
}

/**
 * Working days the short way: three or more days in a row become a range,
 * so [1, 2, 3, 4] reads "Mon–Thu" and [1, 3, 4] "Mon, Wed, Thu".
 *
 * @param weekdays ISO weekdays, Mon=1
 */
export function formatWeekdays(weekdays: number[]): string {
	const names = dayNames()
	const name = (day: number): string => names[day - 1] ?? ''
	const days = [...new Set(weekdays)].sort((a, b) => a - b)
	const runs: [number, ...number[]][] = []
	for (const day of days) {
		const run = runs.at(-1)
		if (run && run.at(-1) === day - 1) {
			run.push(day)
		} else {
			runs.push([day])
		}
	}
	return runs.flatMap((run) => run.length >= 3
		? [`${name(run[0])}–${name(run[run.length - 1] ?? run[0])}`]
		: run.map(name)).join(', ')
}
