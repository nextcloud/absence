/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** A leave type, from LeaveType::jsonSerialize(). */
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

/** Body of POST /api/leave-types. */
export interface LeaveTypeCreateData {
	key: string
	label: string
	color?: string
	icon?: string
	countsAgainstBalance?: boolean
	requiresApproval?: boolean
	requiresNote?: boolean
	requiresReplacement?: boolean
	employeeRequestable?: boolean
	hrOnly?: boolean
	sortOrder?: number
}

/** Body of PUT /api/leave-types/{id}: the key cannot change, every other field is optional. */
export type LeaveTypeUpdateData = Partial<Omit<LeaveType, 'id' | 'key'>>
