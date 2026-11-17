/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { LeaveType } from '../types/index.ts'
import type { RequestFormValues } from './requestPayload.ts'

import { describe, expect, it } from 'vitest'
import { offeredLeaveTypes, requestChanges, requestCreation } from './requestPayload.ts'

function type(id: number, enabled: boolean): LeaveType {
	return {
		id,
		key: 'type' + id,
		label: 'Type ' + id,
		color: '#0082c9',
		icon: '🌴',
		countsAgainstBalance: false,
		requiresApproval: true,
		requiresNote: false,
		requiresReplacement: false,
		employeeRequestable: true,
		hrOnly: false,
		enabled,
		sortOrder: id,
	}
}
const annual = type(1, true)
const special = type(4, false)
const retired = type(5, false)

// A week of special leave (type 4) that bob covers
const stored = { typeId: special.id }
function form(values: Partial<RequestFormValues>): RequestFormValues {
	return {
		typeId: special.id,
		startDate: '2027-06-07',
		endDate: '2027-06-11',
		workingDays: 5,
		reason: 'Moving house',
		asksReplacement: true,
		replacementUid: 'bob',
		...values,
	}
}

describe('offeredLeaveTypes', () => {
	const types = [annual, special, retired]

	it('offers the listed types for a new request', () => {
		expect(offeredLeaveTypes([annual], types, null)).toEqual([annual])
	})

	it('adds the disabled type of the request being edited, and no other disabled one', () => {
		expect(offeredLeaveTypes([annual], types, stored)).toEqual([annual, special])
	})

	it('lists the type of the request being edited once when it is enabled', () => {
		expect(offeredLeaveTypes([annual], types, { typeId: annual.id })).toEqual([annual])
	})
})

describe('requestChanges', () => {
	it('leaves out a type that was not changed', () => {
		expect(requestChanges(form({ endDate: '2027-06-10', workingDays: 4 }), stored)).toEqual({
			startDate: '2027-06-07',
			endDate: '2027-06-10',
			workingDays: 4,
			reason: 'Moving house',
			replacementUid: 'bob',
		})
	})

	it('sends a type that was changed', () => {
		expect(requestChanges(form({ typeId: annual.id }), stored).typeId).toBe(annual.id)
	})

	it('clears a replacement that was removed', () => {
		expect(requestChanges(form({ replacementUid: null }), stored).replacementUid).toBe('')
	})

	it('clears the replacement when the type asks for none', () => {
		expect(requestChanges(form({ typeId: annual.id, asksReplacement: false }), stored).replacementUid).toBe('')
	})

	it('sends the disability flag only when the form shows it', () => {
		expect(requestChanges(form({}), stored)).not.toHaveProperty('disability')
		expect(requestChanges(form({ disability: false }), stored).disability).toBe(false)
	})
})

describe('requestCreation', () => {
	it('sends the type, and a replacement only when one is picked', () => {
		expect(requestCreation(form({}), null)).toEqual({
			typeId: special.id,
			startDate: '2027-06-07',
			endDate: '2027-06-11',
			workingDays: 5,
			reason: 'Moving house',
			replacementUid: 'bob',
		})
		expect(requestCreation(form({ replacementUid: null }), null)).not.toHaveProperty('replacementUid')
		expect(requestCreation(form({ asksReplacement: false }), null)).not.toHaveProperty('replacementUid')
	})

	it('names the employee HR records the absence for', () => {
		expect(requestCreation(form({ disability: true }), 'alice')).toMatchObject({ employeeUid: 'alice', disability: true })
		expect(requestCreation(form({}), null)).not.toHaveProperty('employeeUid')
	})
})
