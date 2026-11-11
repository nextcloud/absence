/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { RequestCoverage } from './coverage.ts'

export type RequestStatus = 'PENDING' | 'ESCALATED' | 'APPROVED' | 'REJECTED' | 'CANCELLED' | 'WITHDRAWAL_PENDING'

/**
 * Whose requests to list: the caller's own, those of the people who report to
 * them ('reports' and 'approvals' are the same list), or everyone (HR only).
 */
export type RequestScope = 'mine' | 'reports' | 'approvals' | 'hr'

/**
 * A request as stored, from LeaveRequest::jsonSerialize(). This is what
 * POST /api/requests answers with: no display names, nothing withheld.
 * Dates are 'YYYY-MM-DD', timestamps ISO 8601.
 */
export interface LeaveRequestRecord {
	id: number
	employeeUid: string
	/** The manager the request was made under, null when there was none: it went straight to HR. */
	managerUid: string | null
	typeId: number
	startDate: string
	endDate: string
	/** Entered by hand, in half-day steps. */
	workingDays: number
	status: RequestStatus
	reason: string | null
	replacementUid: string | null
	attachmentNote: string | null
	decidedBy: string | null
	decidedAt: string | null
	decisionComment: string | null
	/** Whether the request was ever escalated to HR, also after it was decided. */
	escalated: boolean
	/** HR-only flag for disability-related leave (§5.8). */
	disability: boolean
	/** The approved request this one edits, pending re-approval (§5.3). */
	supersedesId: number | null
	createdAt: string | null
	/** Sent back on approve and reject, so the server can tell the request changed meanwhile. */
	updatedAt: string | null
}

/**
 * A request as served to a viewer: the list, update, cancel, approve and reject.
 * For a confidential request (§5.7) seen by anyone but HR the type and the free
 * text about it (reason, attachmentNote, decisionComment) are null. The
 * disability flag is null for every non-HR viewer.
 */
export interface LeaveRequest extends Omit<LeaveRequestRecord, 'typeId' | 'disability'> {
	typeId: number | null
	disability: boolean | null
	employeeName: string
	/** Only present when a replacement is set. */
	replacementName?: string
	/** Only present once somebody decided. */
	decidedByName?: string
}

/** A comment as stored, from RequestComment::jsonSerialize(): the answer of POST /api/requests/{id}/comments. */
export interface RequestCommentRecord {
	id: number
	requestId: number
	authorUid: string
	body: string
	createdAt: string | null
}

/** A comment in a request detail. */
export interface RequestComment extends RequestCommentRecord {
	authorName: string | null
}

export type RequestEventType = 'request_created'
	| 'request_updated'
	| 'request_edited_superseding'
	| 'request_hr_edited'
	| 'request_imported'
	| 'request_approved'
	| 'request_rejected'
	| 'request_cancelled'
	| 'request_escalated'
	| 'withdrawal_requested'
	| 'withdrawal_approved'
	| 'withdrawal_rejected'
	| 'comment_added'
	| 'disability_changed'

/** One entry of a request's history. 'disability_changed' entries are only served to HR. */
export interface RequestEvent {
	id: number
	requestId: number
	/** A uid, 'system' for background jobs, or null for an import run from occ. */
	actorUid: string | null
	actorName: string | null
	eventType: RequestEventType
	/** English text written by the server, not translated. */
	detail: string | null
	createdAt: string | null
}

/** From Attachment::jsonSerialize(). The file itself is at GET /api/attachments/{id}. */
export interface Attachment {
	id: number
	requestId: number
	uploaderUid: string
	name: string
	mime: string
	/** In bytes. */
	size: number
	createdAt: string | null
}

/** Answer of DELETE /api/attachments/{id}. */
export interface AttachmentDeleted {
	deleted: true
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

/** Warning that a request gives less notice than the configured period. */
export interface ShortNotice {
	/** Calendar days until the leave starts, negative once it has started. */
	days: number
	noticePeriod: number
}

/**
 * GET /api/requests/{id}, from RequestService::getDetail(). For a confidential
 * request seen by anyone but HR, comments, history and attachments are empty,
 * the can* flags are false and the three optional keys are absent.
 */
export interface LeaveRequestDetail extends LeaveRequest {
	comments: RequestComment[]
	history: RequestEvent[]
	canDecide: boolean
	canModify: boolean
	/** Empty for anyone but HR and the employee. */
	attachments: Attachment[]
	canAttach: boolean
	/** Present when the viewer may see the employee's balance; null for a type without a ceiling. */
	balance?: RequestBalance | null
	/** Present when the viewer may decide. */
	coverage?: RequestCoverage
	/** Present when the viewer may decide; null when there is nothing to warn about. */
	shortNotice?: ShortNotice | null
}

/** Query of GET /api/requests. */
export interface RequestListParams {
	scope?: RequestScope
	/** One status, or several separated by commas. */
	status?: string | null
	typeId?: number | null
	/** 'YYYY-MM-DD'. */
	from?: string | null
	to?: string | null
	/** Only read with scope 'hr'. */
	employeeUid?: string | null
	/** 1..500, 200 by default. */
	limit?: number
	offset?: number
}

/** Body of POST /api/requests. */
export interface RequestCreateData {
	typeId: number
	startDate: string
	endDate: string
	workingDays?: number | null
	reason?: string | null
	attachmentNote?: string | null
	/** HR records leave on behalf of this person. */
	employeeUid?: string | null
	replacementUid?: string | null
	disability?: boolean | null
}

/**
 * Body of PUT /api/requests/{id}. Omitted or null fields are left unchanged;
 * send '' to clear reason, attachmentNote or replacementUid.
 */
export type RequestUpdateData = Partial<Omit<RequestCreateData, 'employeeUid'>>

/** Body of POST /api/requests/{id}/approve. */
export interface RequestApproveData {
	comment?: string | null
	/** The request's updatedAt as the decider saw it: the server answers 409 if it changed since. */
	updatedAt?: string | null
}

/** Body of POST /api/requests/{id}/reject: the comment is required. */
export interface RequestRejectData {
	comment: string
	updatedAt?: string | null
}

/** Body of POST /api/requests/{id}/comments. */
export interface RequestCommentData {
	body: string
}
