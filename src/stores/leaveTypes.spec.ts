/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { LeaveType } from '../types/index.ts'

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => path }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: vi.fn((app: string, key: string, fallback: unknown) => fallback) }))
vi.mock('@nextcloud/l10n', () => ({ t: (app: string, text: string) => text }))

const { useLeaveTypesStore } = await import('./leaveTypes.ts')

/**
 * @param values what the test is about
 */
function leaveType(values: Partial<LeaveType>): LeaveType {
	return {
		id: 0,
		key: 'type',
		label: '',
		color: '#0082c9',
		icon: '',
		countsAgainstBalance: true,
		requiresApproval: true,
		requiresNote: false,
		requiresReplacement: false,
		employeeRequestable: true,
		hrOnly: false,
		enabled: true,
		sortOrder: 0,
		...values,
	}
}

describe('leave types store', () => {
	let store: ReturnType<typeof useLeaveTypesStore>

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useLeaveTypesStore()
		store.leaveTypes = [
			leaveType({ id: 1, label: 'Annual leave' }),
			leaveType({ id: 2, label: 'Sick leave', employeeRequestable: false }),
			leaveType({ id: 3, label: 'Retired type', enabled: false }),
		]
	})

	it('resolves a known type', () => {
		expect(store.leaveType(1).label).toBe('Annual leave')
	})

	it('renders a withheld type as a neutral "Absent", never a guess', () => {
		// null id = the server withheld the type (neutral shared-calendar
		// visibility); the marker must not invent a reason for the absence.
		expect(store.leaveType(null).label).toBe('Absent')
		expect(store.leaveType(undefined).label).toBe('Absent')
	})

	it('renders an unknown id as "Unknown" rather than crashing', () => {
		expect(store.leaveType(999).label).toBe('Unknown')
	})

	it('only offers enabled, self-requestable types for new requests', () => {
		expect(store.requestableLeaveTypes.map((t) => t.id)).toEqual([1])
		expect(store.enabledLeaveTypes.map((t) => t.id)).toEqual([1, 2])
	})

	it('flags HR-recorded types', () => {
		expect(store.isHrRecorded({ typeId: 2 })).toBe(true)
		expect(store.isHrRecorded({ typeId: 1 })).toBe(false)
	})

	it('treats a withheld (confidential) type as HR-recorded', () => {
		// The server nulls the type of confidential absences for non-HR viewers;
		// those are always HR-recorded, so no approval chip should show either.
		expect(store.isHrRecorded({ typeId: null })).toBe(true)
		expect(store.statusVisible({ typeId: null, status: 'APPROVED' })).toBe(false)
	})

	it('hides the "Approved" chip on HR-recorded leave (no approval concept)', () => {
		expect(store.statusVisible({ typeId: 2, status: 'APPROVED' })).toBe(false)
		expect(store.statusVisible({ typeId: 2, status: 'CANCELLED' })).toBe(true)
		expect(store.statusVisible({ typeId: 1, status: 'APPROVED' })).toBe(true)
	})
})
