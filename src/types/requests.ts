/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { RequestCoverage } from './coverage.ts'

export type RequestStatus = 'PENDING' | 'ESCALATED' | 'APPROVED' | 'REJECTED' | 'CANCELLED' | 'WITHDRAWAL_PENDING'

/** Who a request list is for: own requests, the caller's reports, or everyone (HR). */
export type RequestScope = 'mine' | 'reports' | 'approvals' | 'hr'

/** A request as stored, from LeaveRequest::jsonSerialize(). Dates are 'YYYY-MM-DD', timestamps ISO 8601. */
export interface LeaveRequestRecord {
	id: number
	employeeUid: string
	/** Null when no manager could be resolved: the request goes straight to HR. */
	managerUid: string | null
	typeId: number
	startDate: string
	endDate: string
	workingDays: number
	status: RequestStatus
	reason: string | null
	replacementUid: string | null
	attachmentNote: string | null
	decidedBy: string | null
	decidedAt: string | null
	decisionComment: string | null
	escalated: boolean
	disability: boolean
	/** The approved request this one edits, pending re-approval (§5.3). */
	supersedesId: number | null
	createdAt: string | null
	updatedAt: string | null
}

/**
 * A request as served to a viewer (list, update, cancel, approve, reject).
 * For a confidential request seen by non-HR the type and its free text are
 * withheld (null); the disability flag is null for every non-HR viewer.
 */
export interface LeaveRequest extends Omit<LeaveRequestRecord, 'typeId' | 'disability'> {
	typeId: number | null
	disability: boolean | null
	employeeName: string
	/** Only present when a replacement is set. */
	replacementName?: string
}

export interface RequestComment {
	id: number
	requestId: number
	authorUid: string
	body: string
	createdAt: string | null
}

export type RequestEventType = 'request_created'
	| 'request_updated'
	| 'request_edited_superseding'
	| 'request_hr_edited'
	| 'withdrawal_requested'
	| 'request_cancelled'
	| 'withdrawal_approved'
	| 'request_approved'
	| 'request_rejected'
	| 'withdrawal_rejected'
	| 'request_escalated'
	| 'comment_added'

/** One entry of a request's history. */
export interface RequestEvent {
	id: number
	requestId: number
	/** A user id, or 'system' for background jobs. */
	actorUid: string
	eventType: RequestEventType
	detail: string | null
	createdAt: string | null
}

export interface Attachment {
	id: number
	requestId: number
	uploaderUid: string
	name: string
	mime: string
	/** Size in bytes. */
	size: number
	createdAt: string | null
}

/** The employee's balance for the request's type, in the year the request starts. */
export interface RequestBalance {
	year: number
	entitlement: number
	used: number
	pending: number
	remaining: number
	available: number
}

/** Warning that a request leaves less notice than the configured period. */
export interface ShortNotice {
	/** Days until the leave starts, negative once it has started. */
	days: number
	noticePeriod: number
}

/** GET /api/requests/{id}. */
export interface LeaveRequestDetail extends LeaveRequest {
	comments: RequestComment[]
	history: RequestEvent[]
	canDecide: boolean
	canModify: boolean
	attachments: Attachment[]
	canAttach: boolean
	/** Present when the viewer may see the employee's balance; null for types without a ceiling. */
	balance?: RequestBalance | null
	/** Present when the viewer may decide. */
	coverage?: RequestCoverage
	/** Present when the viewer may decide; null when there is nothing to warn about. */
	shortNotice?: ShortNotice | null
}

/** Query of GET /api/requests. */
export interface RequestListParams {
	scope?: RequestScope
	status?: RequestStatus | null
	typeId?: number | null
	from?: string | null
	to?: string | null
	employeeUid?: string | null
	limit?: number
	offset?: number
}

/** Body of POST /api/requests. employeeUid records leave on behalf of someone (HR). */
export interface RequestCreateData {
	typeId: number
	startDate: string
	endDate: string
	workingDays?: number | null
	reason?: string | null
	attachmentNote?: string | null
	employeeUid?: string | null
	replacementUid?: string | null
	disability?: boolean | null
}

/** Body of PUT /api/requests/{id}. Omitted or null fields are left unchanged. */
export type RequestUpdateData = Partial<Omit<RequestCreateData, 'employeeUid'>>
