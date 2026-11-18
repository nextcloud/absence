/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { LeaveRequestRecord } from '../types/requests.ts'

/**
 * The approvals to remember as celebrated: those still to come or under way.
 * One that has ended, or left the list, never celebrates again, so keeping it
 * would only build up a record of every approval in the browser.
 *
 * @param requests the person's own requests
 * @param today 'YYYY-MM-DD'
 */
export function celebratedIds(requests: Pick<LeaveRequestRecord, 'id' | 'status' | 'endDate'>[], today: string): number[] {
	return requests.filter((r) => r.status === 'APPROVED' && r.endDate >= today).map((r) => r.id)
}
