/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** One balance row per year and leave type, from BalanceService::buildRow(). */
export interface BalanceRow {
	year: number
	typeId: number
	typeKey: string
	typeLabel: string
	typeColor: string
	typeIcon: string
	sortOrder: number
	countsAgainstBalance: boolean
	/** Null when no entitlement is stored yet (defaults apply). */
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
}

/** GET /api/balance and GET /api/employees/{uid}/balance. */
export interface EmployeeBalance {
	employeeUid: string
	balances: BalanceRow[]
}

/** A balance row with the employee it belongs to (team balances and the HR report). */
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
export interface EntitlementEvent {
	id: number
	entitlementId: number
	employeeUid: string
	actorUid: string
	field: EntitlementField
	oldValue: number
	newValue: number
	/** newValue - oldValue, rounded to one decimal. */
	delta: number
	note: string | null
	createdAt: string | null
}

/** Body of PUT /api/entitlements/{id}. adjustmentDelta adds to the manual adjustment. */
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

export interface EntitlementBulkResult {
	/** Number of employees whose entitlement was set. */
	affected: number
}
