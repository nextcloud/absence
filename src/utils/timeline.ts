/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Rows and legend of the month timeline (`TeamTimeline`, §15.2).
 */
import type { LeaveTypeLook } from '../stores/leaveTypes.ts'
import type { CoverageEvent, JsonMap, LeaveType, PersonHoliday } from '../types/index.ts'

import { t } from '@nextcloud/l10n'
import { readableTextColor } from './colors.ts'
import { formatRange } from './dates.ts'
import { labelsByDate } from './holidays.ts'
import { statusMeta } from './status.ts'

/** How a leave type id shows; null is a type the server withheld */
type LookUp = (typeId: number | null) => LeaveTypeLook

/** One absence pill on a person's track */
export interface TimelineSegment {
	requestId: number
	/** Its first day in the month, 0 for the 1st */
	first: number
	/** How many days of the month it covers */
	length: number
	color: string
	textColor: string
	icon: string
	pending: boolean
	title: string
	label: string
}

/** One person with their absences of the month */
export interface TimelineRow {
	uid: string
	name: string
	segments: TimelineSegment[]
	/** Their own public holidays of the month: 'YYYY-MM-DD' to its name */
	holidays: Map<string, string>
}

/** One entry of the legend under the timeline */
export interface TimelineLegendItem {
	key: string
	label: string
	color: string
	icon: string
}

const DAY_MS = 86400000

/**
 * The look of an absence on the timeline.
 *
 * @param typeId its leave type, null when the server withheld it
 * @param look the leave type store's lookup
 */
function segmentLook(typeId: number | null, look: LookUp): LeaveTypeLook {
	if (typeId === null) {
		return { ...look(null), icon: '' }
	}
	return look(typeId)
}

/**
 * One row per person, sorted by name, with the absences that fall in the month.
 *
 * @param events the absences of the month
 * @param holidays each person's public holidays, by uid
 * @param year the year shown
 * @param month the month shown, 0 for January
 * @param look the leave type store's lookup
 */
export function timelineRows(events: CoverageEvent[], holidays: JsonMap<PersonHoliday[]>, year: number, month: number, look: LookUp): TimelineRow[] {
	const byUid = new Map<string, TimelineRow>()
	const monthStart = new Date(year, month, 1).getTime()
	const lastIndex = new Date(year, month + 1, 0).getDate() - 1
	const holidaysByUid = new Map(Array.isArray(holidays) ? [] : Object.entries(holidays))
	for (const ev of events) {
		let row = byUid.get(ev.employeeUid)
		if (row === undefined) {
			row = {
				uid: ev.employeeUid,
				name: ev.displayName,
				segments: [],
				holidays: labelsByDate(holidaysByUid.get(ev.employeeUid) ?? []),
			}
			byUid.set(ev.employeeUid, row)
		}
		const startIdx = Math.max(0, Math.round((new Date(ev.start + 'T00:00:00').getTime() - monthStart) / DAY_MS))
		const endIdx = Math.min(lastIndex, Math.round((new Date(ev.end + 'T00:00:00').getTime() - monthStart) / DAY_MS))
		if (endIdx < 0 || startIdx > lastIndex) {
			continue
		}
		const type = segmentLook(ev.typeId, look)
		const dates = formatRange(ev.start, ev.end)
		const status = statusMeta(ev.status).label
		row.segments.push({
			requestId: ev.requestId,
			first: startIdx,
			length: endIdx - startIdx + 1,
			color: type.color,
			textColor: readableTextColor(type.color),
			icon: type.icon,
			pending: ev.status !== 'APPROVED',
			title: `${type.label} · ${dates}${ev.status !== 'APPROVED' ? ' (' + status + ')' : ''}`,
			// What a screen reader gets instead of position, colour and stripes
			label: t('absence', '{person}: {type}, {dates}, {status}', { person: ev.displayName, type: type.label, dates, status }, undefined, { escape: false, sanitize: false }),
		})
	}
	return [...byUid.values()].sort((a, b) => a.name.localeCompare(b.name))
}

/**
 * The leave types on show, in the order of the type list, then "Absent" for
 * absences whose type was withheld.
 *
 * @param events the absences of the month
 * @param types every leave type
 * @param look the leave type store's lookup
 */
export function timelineLegend(events: CoverageEvent[], types: LeaveType[], look: LookUp): TimelineLegendItem[] {
	const ids = new Set(events.map((ev) => ev.typeId))
	const items = types.filter((type) => ids.has(type.id))
		.map((type) => ({ key: String(type.id), label: type.label, color: type.color, icon: type.icon }))
	if (ids.has(null)) {
		const away = segmentLook(null, look)
		items.push({ key: 'away', label: away.label, color: away.color, icon: away.icon })
	}
	return items
}
