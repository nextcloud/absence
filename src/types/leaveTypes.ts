/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A leave type, from LeaveType::jsonSerialize(). Confidential types are left
 * out of every list served to anyone but HR, the initial state included.
 */
export interface LeaveType {
	id: number
	key: string
	label: string
	/** Hex color such as #0082c9. */
	color: string
	/** Emoji shown next to the label. */
	icon: string
	countsAgainstBalance: boolean
	requiresApproval: boolean
	requiresNote: boolean
	requiresReplacement: boolean
	/** False for HR-recorded types such as sick leave. */
	employeeRequestable: boolean
	/** Confidential category, only visible to HR (§5.7). */
	hrOnly: boolean
	enabled: boolean
	sortOrder: number
}

/** Query of GET /api/leave-types. */
export interface LeaveTypeListParams {
	onlyEnabled?: boolean
}

/** Body of POST /api/leave-types (HR only). A new type is always enabled. */
export interface LeaveTypeCreateData {
	key: string
	label: string
	color?: string
	icon?: string
	/** False when left out, unlike the other flags, which default as documented on the type. */
	countsAgainstBalance?: boolean
	requiresApproval?: boolean
	requiresNote?: boolean
	requiresReplacement?: boolean
	employeeRequestable?: boolean
	hrOnly?: boolean
	sortOrder?: number
}

/**
 * Body of PUT /api/leave-types/{id} (HR only): the key cannot change, every
 * other field is optional. DELETE /api/leave-types/{id} disables the type and
 * answers with it.
 */
export type LeaveTypeUpdateData = Partial<Omit<LeaveType, 'id' | 'key'>>
