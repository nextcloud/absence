/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Whether a route is open to this session. HR pages are for HR, and the
 * settings are also for admins, who set the app up before HR exists.
 *
 * @param {{ hr?: boolean, admin?: boolean }} meta the route's meta
 * @param {{ isHr?: boolean, isAdmin?: boolean }} session the current session
 * @return {boolean}
 */
export function canOpenRoute(meta, session) {
	if (!meta.hr) {
		return true
	}
	return !!session.isHr || (!!meta.admin && !!session.isAdmin)
}
