/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { EmployeeBalanceRow } from '../types/index.ts'

import { describe, expect, it } from 'vitest'
import { availableByEmployeeAndType } from './balances.ts'

/**
 * @param values what the test is about
 */
function row(values: Partial<EmployeeBalanceRow>): EmployeeBalanceRow {
	return { employeeUid: 'alice', typeId: 1, entitlement: 28, used: 15, pending: 4, remaining: 13, available: 9, ...values } as EmployeeBalanceRow
}

describe('availableByEmployeeAndType', () => {
	it('keeps the available days, after pending requests, per person and type', () => {
		expect(availableByEmployeeAndType([
			row({}),
			row({ employeeUid: 'bob', remaining: 19, available: 16 }),
		])).toEqual({ 'alice|1': 9, 'bob|1': 16 })
	})

	it('leaves out a type that counts against nothing', () => {
		expect(availableByEmployeeAndType([row({ typeId: 4, entitlement: null, remaining: null, available: null })])).toEqual({})
	})
})
