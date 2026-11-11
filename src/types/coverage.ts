/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { JsonMap } from './common.ts'
import type { RequestStatus } from './requests.ts'

/** Whose absences to show: the caller's team, or everyone (HR only). */
export type CoverageScope = 'team' | 'company'

/** One absence in a coverage or calendar range. */
export interface CoverageEvent {
	requestId: number
	employeeUid: string
	displayName: string
	/** Null when the viewer may not see the category: a colleague's leave under the neutral calendar policy, or a confidential type for anyone but HR. */
	typeId: number | null
	/** Never REJECTED or CANCELLED. */
	status: RequestStatus
	/** 'YYYY-MM-DD', like end. */
	start: string
	end: string
}

/** GET /api/coverage, from CoverageService::getCoverage(). */
export interface Coverage {
	events: CoverageEvent[]
	/** Approved absences per 'YYYY-MM-DD'; days without any are left out. */
	byDate: JsonMap<number>
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

/** Query of GET /api/coverage and GET /api/calendar. */
export interface CoverageParams {
	/** 'YYYY-MM-DD'. */
	from: string
	to: string
	/** 'team' by default, and for a non-HR caller asking for 'company'. */
	scope?: CoverageScope
}

/** GET /api/calendar. */
export interface CalendarResponse {
	events: CoverageEvent[]
}
