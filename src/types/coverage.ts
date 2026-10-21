/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { RequestStatus } from './requests.ts'

/** Whose absences to show: the caller's team, or everyone (HR only). */
export type CoverageScope = 'team' | 'company'

/** One absence in a coverage or calendar range. */
export interface CoverageEvent {
	requestId: number
	employeeUid: string
	displayName: string
	/** Null when the viewer may not see the category. */
	typeId: number | null
	status: RequestStatus
	start: string
	end: string
}

/** GET /api/coverage, from CoverageService::getCoverage(). */
export interface Coverage {
	events: CoverageEvent[]
	/**
	 * Approved absences per 'YYYY-MM-DD'. PHP encodes an empty map as [], which
	 * reads the same way (every lookup is undefined).
	 */
	byDate: Record<string, number>
	maxConcurrent: number
	/** Configured maximum of concurrent absences, 0 when disabled. */
	threshold: number
	conflict: boolean
}

/** Coverage of the other team members while a request runs, from CoverageService::getRequestCoverage(). */
export interface RequestCoverage extends Coverage {
	/** Peak concurrency if the request were approved. */
	projectedPeak: number
}

/** GET /api/calendar. */
export interface CalendarResponse {
	events: CoverageEvent[]
}
