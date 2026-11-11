/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { InitialState, SessionInfo } from '../types/index.ts'

import { loadState } from '@nextcloud/initial-state'
import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../services/api.ts'
import { logger } from '../services/logger.ts'

export const useSessionStore = defineStore('session', () => {
	// The page always provides the session. A missing one only means the template changed.
	const session = ref<SessionInfo>(loadState<InitialState['session']>('absence', 'session', { uid: null }))

	/**
	 * Reads the session again: the roles, the counts of the navigation and the settings it carries.
	 */
	async function refreshSession(): Promise<void> {
		try {
			session.value = await api.getSession()
		} catch (e) {
			logger.error('Failed to refresh session', { error: e })
		}
	}

	return { session, refreshSession }
})
