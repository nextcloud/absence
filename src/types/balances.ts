/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * One balance row per year and leave type, from BalanceService::buildRow().
 * Rows of confidential types (§5.7) are left out for anyone but HR.
 */
export interface BalanceRow {
	year: number
	typeId: number
	typeKey: string
	typeLabel: string
	typeColor: string
	typeIcon: string
	sortOrder: number
	countsAgainstBalance: boolean
	/** Null when no entitlement is stored yet (the default applies) and for types that count against nothing. */
	entitlementId: number | null
	baseDays: number
	carryOverDays: number
	manualAdjustment: number
	/** Null, like remaining and available, for types that count against nothing. */
	entitlement: number | null
	used: number
	pending: number
	remaining: number | null
	available: number | null
	/** Carried-over days the expiry would remove as things stand (§6.2); 0 when nothing is at risk, and on every year but the running one. */
	carryOverExpiring: number
	/** 'YYYY-MM-DD', null whenever carryOverExpiring is 0. */
	carryOverExpiryDate: string | null
}

/** GET /api/balance and GET /api/employees/{uid}/balance. */
export interface EmployeeBalance {
	employeeUid: string
	balances: BalanceRow[]
}

/** A row of GET /api/team/balances (the caller's direct reports) and GET /api/reports/balances (HR). */
export interface EmployeeBalanceRow extends BalanceRow {
	employeeUid: string
	displayName: string
}

/** A stored entitlement, from Entitlement::jsonSerialize(). */
export interface Entitlement {
	id: number
	employeeUid: string
	year: number
	typeId: number
	baseDays: number
	carryOverDays: number
	manualAdjustment: number
	adjustmentNote: string | null
	/** baseDays + carryOverDays + manualAdjustment. */
	entitlement: number
}

export type EntitlementField = 'base_days' | 'carry_over_days' | 'manual_adjustment'

/** One change to an entitlement figure, from EntitlementEvent::jsonSerialize(). */
export interface EntitlementEventRecord {
	id: number
	entitlementId: number
	employeeUid: string
	/** A uid, 'system' for the year rollover and the carry-over expiry, or null for a change made from occ. */
	actorUid: string | null
	field: EntitlementField
	oldValue: number
	newValue: number
	/** newValue - oldValue, rounded to one decimal. */
	delta: number
	note: string | null
	createdAt: string | null
}

/** One entry of GET /api/entitlements/{id}/history (HR only). */
export interface EntitlementEvent extends EntitlementEventRecord {
	/** Null when actorUid is null; the uid itself when no account has it ('system'). */
	actorName: string | null
}

/** Query of GET /api/balance, /api/team/balances and /api/employees/{uid}/balance. */
export interface BalanceParams {
	/** Without it: the current year, plus for the single-employee endpoints every year with entitlements or leave. */
	year?: number | null
}

/** Query of GET /api/entitlements (HR only). */
export interface EntitlementListParams {
	employeeUid: string
	year?: number | null
}

/** Body of PUT /api/entitlements/{id}. Null or omitted fields are left unchanged; adjustmentDelta adds to the manual adjustment. */
export interface EntitlementUpdateData {
	baseDays?: number | null
	carryOverDays?: number | null
	manualAdjustment?: number | null
	adjustmentDelta?: number | null
	adjustmentNote?: string | null
}

/** Body of POST /api/entitlements. */
export interface EntitlementCreateData extends EntitlementUpdateData {
	employeeUid: string
	year: number
	typeId: number
}

/** Body of POST /api/entitlements/bulk. */
export interface EntitlementBulkData {
	year: number
	typeId: number
	baseDays: number
	group?: string | null
}

/** Answer of POST /api/entitlements/bulk. */
export interface EntitlementBulkResult {
	/** Number of employees whose entitlement was set. */
	affected: number
}
