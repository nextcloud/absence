/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { LeaveRequest, LeaveType, RequestCreateData, RequestUpdateData } from '../types/index.ts'

/** What the request form holds when it is submitted */
export interface RequestFormValues {
	/** The picked type, or the confidential category picked under sick leave */
	typeId: number
	/** 'YYYY-MM-DD' */
	startDate: string
	/** 'YYYY-MM-DD' */
	endDate: string
	workingDays: number
	reason: string
	/** Left out when the form does not show the flag */
	disability?: boolean
	/** Whether the picked type asks who covers */
	asksReplacement: boolean
	/** The colleague picked to cover, null when nobody is */
	replacementUid: string | null
}

/**
 * The leave types a picker of the request form offers: the ones given, and the
 * type of the request being edited when it was disabled since.
 *
 * @param offered the enabled types the picker lists
 * @param types every leave type the viewer knows
 * @param request the request being edited, null for a new one
 */
export function offeredLeaveTypes(offered: LeaveType[], types: LeaveType[], request: Pick<LeaveRequest, 'typeId'> | null): LeaveType[] {
	const disabled = request === null ? undefined : types.find((type) => type.id === request.typeId && !type.enabled)
	return disabled ? [...offered, disabled] : offered
}

/**
 * The body that creates a request from the form.
 *
 * @param form what the form holds
 * @param employeeUid whose absence HR records, null for the user's own
 */
export function requestCreation(form: RequestFormValues, employeeUid: string | null): RequestCreateData {
	const { asksReplacement, replacementUid, ...values } = form
	const creation: RequestCreateData = { ...values }
	if (asksReplacement && replacementUid) {
		creation.replacementUid = replacementUid
	}
	if (employeeUid) {
		creation.employeeUid = employeeUid
	}
	return creation
}

/**
 * The body that saves the form onto the request being edited. The type is only
 * sent when it was changed, and the replacement always is: '' removes the stored one.
 *
 * @param form what the form holds
 * @param request the request being edited
 */
export function requestChanges(form: RequestFormValues, request: Pick<LeaveRequest, 'typeId'>): RequestUpdateData {
	const { typeId, asksReplacement, replacementUid, ...values } = form
	const changes: RequestUpdateData = { ...values, replacementUid: (asksReplacement && replacementUid) || '' }
	if (typeId !== request.typeId) {
		changes.typeId = typeId
	}
	return changes
}
