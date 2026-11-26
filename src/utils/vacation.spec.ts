/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { RequestStatus } from '../types/index.ts'

import { describe, expect, it } from 'vitest'
import { vacationSplit } from './vacation.ts'

const row = { typeId: 1, year: 2026, used: 24, pending: 2 }
const leave = (startDate: string, endDate: string, workingDays: number, status: RequestStatus = 'APPROVED', typeId = 1) => ({ typeId, status, startDate, endDate, workingDays })

describe('vacationSplit', () => {
	it('counts the leave starting after today as planned, the rest of the used days as taken', () => {
		const split = vacationSplit(row, [leave('2026-03-02', '2026-03-06', 5), leave('2026-12-22', '2026-12-31', 4)], '2026-11-26')
		expect(split.taken).toBe(20)
		expect(split.planned).toBe(4)
		expect(split.pending).toBe(2)
	})

	it('counts leave that started already as taken, and leave starting next year in that year', () => {
		const split = vacationSplit(row, [leave('2026-11-25', '2026-11-27', 3), leave('2027-01-04', '2027-01-08', 5)], '2026-11-26')
		expect(split.planned).toBe(0)
		expect(split.taken).toBe(24)
	})

	it('leaves out other types, cancelled leave and requests still waiting from the planned days', () => {
		const split = vacationSplit(row, [leave('2026-12-01', '2026-12-01', 1, 'APPROVED', 2), leave('2026-12-02', '2026-12-02', 1, 'CANCELLED'), leave('2026-12-03', '2026-12-03', 1, 'PENDING')], '2026-11-26')
		expect(split.planned).toBe(0)
	})

	it('never plans more than is used', () => {
		expect(vacationSplit({ ...row, used: 2 }, [leave('2026-12-22', '2026-12-31', 4)], '2026-11-26')).toMatchObject({ taken: 0, planned: 2 })
	})

	it('names the next leave, booked or waiting for approval', () => {
		const requests = [leave('2027-01-04', '2027-01-08', 5), leave('2026-12-14', '2026-12-15', 2, 'PENDING'), leave('2026-11-02', '2026-11-03', 2)]
		expect(vacationSplit(row, requests, '2026-11-26').next).toEqual({ startDate: '2026-12-14', endDate: '2026-12-15', workingDays: 2, pending: true })
		expect(vacationSplit(row, [], '2026-11-26').next).toBeNull()
	})
})
