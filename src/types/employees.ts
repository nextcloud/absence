/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** A result of the employee search (guests excluded). */
export interface EmployeeSearchResult {
	uid: string
	displayName: string
}

/** A Nextcloud group, for the HR report and export filters. */
export interface Group {
	id: string
	displayName: string
}
