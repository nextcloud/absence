/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** What a route asks of the person opening it */
export interface RouteAccess {
	hr?: boolean
	admin?: boolean
}

/** The part of the session that decides which pages are open */
export interface AccessRoles {
	/** Null without a signed-in user */
	uid?: string | null
	isHr?: boolean
	isAdmin?: boolean
}

/**
 * Whether a route is open to this session. HR pages are for HR, and the
 * settings are also for admins, who set the app up before HR exists.
 *
 * @param meta the route's meta
 * @param session the current session
 */
export function canOpenRoute(meta: RouteAccess, session: AccessRoles): boolean {
	if (!meta.hr) {
		return true
	}
	return !!session.isHr || (!!meta.admin && !!session.isAdmin)
}
