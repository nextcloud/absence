/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { n, t } from '@nextcloud/l10n'
import { formatNumber } from './locale.ts'

/**
 * A duration: "under an hour" below one hour, in hours below two days, in days
 * from there.
 *
 * @param h the hours, null when there is nothing to measure
 */
export function formatHours(h: number | null): string {
	if (h === null || h === undefined) {
		return '–'
	}
	if (h < 1) {
		return t('absence', 'under an hour')
	}
	if (h < 48) {
		return n('absence', '%n hour', '%n hours', Math.round(h))
	}
	const days = Math.round(h / 24 * 10) / 10
	return n('absence', '{days} day', '{days} days', days, { days: formatNumber(days) })
}
