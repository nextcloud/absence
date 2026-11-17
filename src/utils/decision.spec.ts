/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { RequestStatus } from '../types/index.ts'

import { describe, expect, it } from 'vitest'
import { requestPage, waitingFor } from './decision.ts'

describe('waitingFor', () => {
	const now = new Date(2026, 10, 17, 9, 0)

	it('counts calendar days, not 24-hour periods', () => {
		expect(waitingFor(new Date(2026, 10, 16, 23, 0).toISOString(), now)).toBe('Waiting 1 day')
		expect(waitingFor(new Date(2026, 10, 15, 10, 0).toISOString(), now)).toBe('Waiting 2 days')
	})

	it('says when the request came in today', () => {
		expect(waitingFor(new Date(2026, 10, 17, 8, 0).toISOString(), now)).toBe('Asked today')
	})
})

describe('requestPage', () => {
	const request = (employeeUid: string, status: RequestStatus, canDecide: boolean, managerUid: string | null = 'dave') => ({ employeeUid, managerUid, status, canDecide })
	const manager = { uid: 'dave', isHr: false }
	const hr = { uid: 'eve', isHr: true }

	it('opens the viewer\'s own request over My leave', () => {
		expect(requestPage(request('dave', 'PENDING', false, 'eve'), manager)).toBe('my')
	})

	it('opens a request waiting for the viewer over Approvals', () => {
		expect(requestPage(request('alice', 'PENDING', true), manager)).toBe('approvals')
		expect(requestPage(request('alice', 'WITHDRAWAL_PENDING', true), manager)).toBe('approvals')
		expect(requestPage(request('frank', 'ESCALATED', true, 'grace'), hr)).toBe('approvals')
	})

	it('opens a decided request of a report over the Team view', () => {
		expect(requestPage(request('alice', 'APPROVED', true), manager)).toBe('team')
	})

	it('opens somebody else\'s request over the Team view', () => {
		expect(requestPage(request('bob', 'PENDING', false), { uid: 'alice', isHr: false })).toBe('team')
	})

	it('opens a request HR could override but that is not in its queue over Absences', () => {
		expect(requestPage(request('alice', 'PENDING', true), hr)).toBe('hr-absences')
		expect(requestPage(request('alice', 'APPROVED', true), hr)).toBe('hr-absences')
	})
})
