/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Approved days of one leave type in a trends range. */
export interface TrendsByType {
	typeId: number
	/** Falls back to the id as a string when the type no longer exists. */
	typeLabel: string
	typeColor: string
	typeIcon: string
	days: number
}

/** GET /api/reports/trends, from ReportService::trends(). */
export interface Trends {
	/**
	 * Approved working days per 'YYYY-MM', by start month. PHP encodes an empty
	 * map as [].
	 */
	byMonth: Record<string, number>
	byType: TrendsByType[]
	total: number
}

/** A leave type counted as sickness in the sick-leave report. */
export interface SickLeaveType {
	id: number
	key: string
	label: string
	color: string
	icon: string
}

export interface SickLeaveRow {
	employeeUid: string
	displayName: string
	days: number
	episodes: number
	longestEpisode: number
	/** End date of the latest episode, null without any. */
	lastDate: string | null
}

/** GET /api/reports/sick-leave, from ReportService::sickLeaveReport(). */
export interface SickLeaveReport {
	year: number
	/** Empty when no type matches, so there is nothing to report on. */
	types: SickLeaveType[]
	rows: SickLeaveRow[]
	totals: {
		employees: number
		affected: number
		days: number
		episodes: number
	}
}

export interface ManagerApprovalStats {
	managerUid: string
	name: string
	decided: number
	escalated: number
	medianHours: number | null
}

export interface ApprovalHealth {
	decided: number
	medianHours: number | null
	p95Hours: number | null
	/** Share of requests that were escalated, 0..1. */
	escalationRate: number
	escalatedCount: number
	needingDecision: number
	/** Slowest median first, top 10. */
	perManager: ManagerApprovalStats[]
}

export interface BradfordRow {
	employeeUid: string
	name: string
	spells: number
	days: number
	/** spells² × days. */
	score: number
}

export interface UtilizationRate {
	used: number
	entitlement: number
	/** used / entitlement, 0..1 (can exceed 1 when overdrawn). */
	rate: number
}

export interface TeamUtilization extends UtilizationRate {
	name: string
}

/** Somebody who has not had a break in a while. */
export interface NeglectedRow {
	employeeUid: string
	name: string
	lastLeaveDate: string | null
	/** Null when there was no break within the last year. */
	daysSince: number | null
}

export interface Utilization {
	company: UtilizationRate
	perTeam: TeamUtilization[]
	neglected: NeglectedRow[]
}

export interface TeamLiability {
	name: string
	outstanding: number
	carryOverExposure: number
}

export interface Liability {
	outstanding: number
	carryOverExposure: number
	/** 'YYYY-MM-DD' the carry-over expires, null when it does not. */
	expiryDate: string | null
	perTeam: TeamLiability[]
}

/** GET /api/reports/insights, from InsightsService::getInsights(). */
export interface Insights {
	year: number
	approval: ApprovalHealth
	bradford: BradfordRow[]
	utilization: Utilization
	liability: Liability
}
