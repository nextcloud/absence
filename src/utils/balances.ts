/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { EmployeeBalanceRow } from '../types/index.ts'

/**
 * The days each person can still book, per leave type: entitlement minus used
 * minus pending. Keyed `employeeUid|typeId`; types that count against nothing
 * are left out.
 *
 * @param rows a balance report
 */
export function availableByEmployeeAndType(rows: EmployeeBalanceRow[]): Record<string, number> {
	const index: Record<string, number> = {}
	for (const row of rows) {
		if (row.available !== null) {
			index[`${row.employeeUid}|${row.typeId}`] = row.available
		}
	}
	return index
}
