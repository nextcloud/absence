/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { LeaveRequestDetail, RequestStatus } from '../types/index.ts'

import { n, t } from '@nextcloud/l10n'

/** The statuses the Approvals queues list */
const WAITING: RequestStatus[] = ['PENDING', 'ESCALATED', 'WITHDRAWAL_PENDING']

/** The pages a request can open over */
export type RequestPage = 'my' | 'approvals' | 'hr-absences' | 'team'

/**
 * How long a request has waited for a decision, in calendar days of the viewer.
 *
 * @param since when it was asked for, ISO 8601
 * @param now the current time
 */
export function waitingFor(since: string, now: Date): string {
	const asked = new Date(since)
	const days = Math.round((startOfDay(now) - startOfDay(asked)) / 86400000)
	if (days < 1) {
		return t('absence', 'Asked today')
	}
	return n('absence', 'Waiting %n day', 'Waiting %n days', days)
}

/**
 * @param date a point in time
 */
function startOfDay(date: Date): number {
	return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime()
}

/**
 * The page to show behind a request opened from a link: the viewer's own leave,
 * the Approvals queue when it waits there for them, otherwise the page that
 * lists other people's leave.
 *
 * @param request the request, as its detail is served to the viewer
 * @param session the viewer
 * @param session.uid the viewer's user id
 * @param session.isHr whether the viewer is in HR
 */
export function requestPage(
	request: Pick<LeaveRequestDetail, 'employeeUid' | 'managerUid' | 'status' | 'canDecide'>,
	session: { uid: string | null, isHr?: boolean },
): RequestPage {
	if (request.employeeUid === session.uid) {
		return 'my'
	}
	if (request.canDecide && WAITING.includes(request.status)
		// HR may decide anything, but its queue holds only escalations and its own reports
		&& (!session.isHr || request.status === 'ESCALATED' || request.managerUid === session.uid)) {
		return 'approvals'
	}
	return session.isHr ? 'hr-absences' : 'team'
}
