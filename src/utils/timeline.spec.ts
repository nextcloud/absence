/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { LeaveTypeLook } from '../stores/leaveTypes.ts'
import type { CoverageEvent, LeaveType } from '../types/index.ts'

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	t: (app: string, text: string, vars: Record<string, string> = {}) => text.replace(/{(\w+)}/g, (_, key: string) => vars[key] ?? key),
	getCanonicalLocale: () => 'en-US',
}))

const { timelineLegend, timelineRows } = await import('./timeline.ts')

const annual = { id: 1, label: 'Annual leave', color: '#2e7d32', icon: '🌴' } as LeaveType
const sick = { id: 2, label: 'Sick leave', color: '#c77a1e', icon: '🤒' } as LeaveType

/**
 * The store's lookup: a withheld type is a grey dot.
 *
 * @param id the leave type id
 */
function look(id: number | null): LeaveTypeLook {
	if (id === null) {
		return { label: 'Absent', color: '#888', icon: '•' }
	}
	return [annual, sick].find((type) => type.id === id)!
}

/**
 * An approved absence in November 2026.
 *
 * @param uid whose absence
 * @param typeId its leave type
 * @param start first day, 'YYYY-MM-DD'
 * @param end last day
 */
function absence(uid: string, typeId: number | null, start = '2026-11-09', end = '2026-11-11'): CoverageEvent {
	return { requestId: 1, employeeUid: uid, displayName: uid, typeId, status: 'APPROVED', start, end }
}

describe('timelineRows', () => {
	it('keeps uids that are also names of object properties apart', () => {
		const rows = timelineRows([
			absence('constructor', 1),
			absence('__proto__', 1),
			absence('toString', 1),
		], { constructor: [{ date: '2026-11-11', label: 'St. Martin' }] }, 2026, 10, look)

		expect(rows.map((row) => row.uid)).toEqual(['__proto__', 'constructor', 'toString'])
		expect(rows.map((row) => row.segments.length)).toEqual([1, 1, 1])
		expect(rows[1]?.holidays.get('2026-11-11')).toBe('St. Martin')
		expect(rows[2]?.holidays.size).toBe(0)
	})

	it('places an absence by day, clipped to the month', () => {
		const [row] = timelineRows([
			absence('alice', 1, '2026-10-28', '2026-11-02'),
			absence('alice', 1, '2026-11-30', '2026-12-04'),
		], [], 2026, 10, look)

		expect(row?.segments.map(({ first, length }) => ({ first, length }))).toEqual([
			{ first: 0, length: 2 },
			{ first: 29, length: 1 },
		])
	})

	it('shows a withheld type as Absent, without an icon', () => {
		const [row] = timelineRows([absence('bob', null)], [], 2026, 10, look)

		expect(row?.segments[0]?.icon).toBe('')
		expect(row?.segments[0]?.label).toBe('bob: Absent, Nov 9, 2026 – Nov 11, 2026, Approved')
	})
})

describe('timelineLegend', () => {
	it('lists the types on show, then Absent for withheld ones', () => {
		const legend = timelineLegend([absence('bob', null), absence('alice', 2)], [annual, sick], look)

		expect(legend.map(({ label, icon }) => ({ label, icon }))).toEqual([
			{ label: 'Sick leave', icon: '🤒' },
			{ label: 'Absent', icon: '' },
		])
	})
})
