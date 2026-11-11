/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { BalanceRow } from '../types/index.ts'

import { n } from '@nextcloud/l10n'
import { formatDate } from './dates.ts'
import { formatNumber } from './locale.ts'

/**
 * The line that tells whose balance this is that carried-over days are about
 * to expire (§6.2), or '' when the row has nothing to lose.
 *
 * @param row the balance row
 */
export function carryOverExpiryNotice(row: Pick<BalanceRow, 'carryOverExpiring' | 'carryOverExpiryDate'>): string {
	if (!(row.carryOverExpiring > 0) || !row.carryOverExpiryDate) {
		return ''
	}
	return n(
		'absence',
		'{count} carried-over day expires on {date}',
		'{count} carried-over days expire on {date}',
		// half a day is still one day to the plural rules, 1.5 are several
		Math.ceil(row.carryOverExpiring),
		{ count: formatNumber(row.carryOverExpiring), date: formatDate(row.carryOverExpiryDate) },
	)
}
