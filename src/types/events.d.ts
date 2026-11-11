/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { LeaveRequest } from './requests.ts'

// The window events the views and the app shell talk through.
declare global {
	interface WindowEventMap {
		/** Something changed: every open list reloads */
		'absence:refresh': CustomEvent<undefined>
		/** A request was sent: throw the confetti */
		'absence:celebrate': CustomEvent<undefined>
		/** Open the form for a new request */
		'absence:open-new': CustomEvent<undefined>
		/** Open the form HR records an absence with */
		'absence:open-record': CustomEvent<undefined>
		/** Open the form on an existing request */
		'absence:open-edit': CustomEvent<LeaveRequest>
	}
}
