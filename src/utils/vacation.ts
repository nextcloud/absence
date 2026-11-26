/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { BalanceRow, LeaveRequest, RequestStatus } from '../types/index.ts'

/** Statuses whose days count as used, as on the server (§4.2) */
const USED: RequestStatus[] = ['APPROVED', 'WITHDRAWAL_PENDING']
/** Statuses still waiting for a decision */
const PENDING: RequestStatus[] = ['PENDING', 'ESCALATED']

/** The next leave of a type, booked or waiting for approval */
export interface NextLeave {
	startDate: string
	endDate: string
	workingDays: number
	pending: boolean
}

/** How the vacation days of a year split up */
export interface VacationSplit {
	/** Used days whose leave started today or before */
	taken: number
	/** Used days whose leave starts after today */
	planned: number
	pending: number
	next: NextLeave | null
}

/**
 * Splits the used days of a balance row into taken and planned, the way the
 * server counts them: each request's days in the year its leave starts.
 *
 * @param row the balance of the type and year
 * @param requests the person's requests
 * @param today 'YYYY-MM-DD'
 */
export function vacationSplit(row: Pick<BalanceRow, 'typeId' | 'year' | 'used' | 'pending'>, requests: Pick<LeaveRequest, 'typeId' | 'status' | 'startDate' | 'endDate' | 'workingDays'>[], today: string): VacationSplit {
	const ofType = requests.filter((request) => request.typeId === row.typeId)
	const planned = ofType
		.filter((request) => USED.includes(request.status) && request.startDate > today && request.startDate.startsWith(`${row.year}-`))
		.reduce((sum, request) => sum + request.workingDays, 0)
	const upcoming = ofType
		.filter((request) => (USED.includes(request.status) || PENDING.includes(request.status)) && request.startDate > today)
		.sort((a, b) => a.startDate.localeCompare(b.startDate))[0]
	const clamped = Math.min(planned, row.used)
	return {
		taken: row.used - clamped,
		planned: clamped,
		pending: row.pending,
		next: upcoming
			? { startDate: upcoming.startDate, endDate: upcoming.endDate, workingDays: upcoming.workingDays, pending: PENDING.includes(upcoming.status) }
			: null,
	}
}
