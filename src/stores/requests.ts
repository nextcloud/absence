/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type {
	BalanceRow,
	LeaveRequest,
	LeaveRequestRecord,
	RequestCreateData,
	RequestListParams,
	RequestUpdateData,
} from '../types/index.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../services/api.ts'
import { useSessionStore } from './session.ts'

export const useRequestsStore = defineStore('requests', () => {
	const sessionStore = useSessionStore()

	const requests = ref<LeaveRequest[]>([])
	const balance = ref<{ balances: BalanceRow[] }>({ balances: [] })
	const loading = ref(false)
	const selectedId = ref<number | null>(null)

	/**
	 * @param params which requests to list
	 */
	async function loadRequests(params?: RequestListParams): Promise<void> {
		loading.value = true
		try {
			requests.value = await api.listRequests(params)
		} catch {
			showError(t('absence', 'Could not load requests'))
		} finally {
			loading.value = false
		}
	}

	/**
	 * @param year the year to read, every year when left out
	 */
	async function loadMyBalance(year?: number): Promise<void> {
		// Mirrors loadRequests: callers run the two in a Promise.all, so an
		// unhandled rejection here took the request list down with it.
		try {
			balance.value = await api.getMyBalance(year)
		} catch {
			showError(t('absence', 'Could not load your balance'))
		}
	}

	/**
	 * @param data the request to send
	 */
	async function createRequest(data: RequestCreateData): Promise<LeaveRequestRecord> {
		const created = await api.createRequest(data)
		showSuccess(t('absence', 'On its way ✈️'))
		await sessionStore.refreshSession()
		return created
	}

	/**
	 * @param id the request to change
	 * @param data its new values
	 */
	async function updateRequest(id: number, data: RequestUpdateData): Promise<LeaveRequest> {
		const updated = await api.updateRequest(id, data)
		showSuccess(t('absence', 'Request updated'))
		return updated
	}

	/**
	 * @param id the request to cancel
	 */
	async function cancelRequest(id: number): Promise<LeaveRequest> {
		const res = await api.cancelRequest(id)
		showSuccess(t('absence', 'Request cancelled'))
		await sessionStore.refreshSession()
		return res
	}

	/**
	 * @param id the request to approve
	 * @param comment a note for the employee
	 * @param updatedAt the request as the decider saw it
	 */
	async function approveRequest(id: number, comment?: string | null, updatedAt?: string | null): Promise<LeaveRequest> {
		const res = await api.approveRequest(id, comment, updatedAt)
		await sessionStore.refreshSession()
		return res
	}

	/**
	 * @param id the request to decline
	 * @param comment why
	 * @param updatedAt the request as the decider saw it
	 */
	async function rejectRequest(id: number, comment: string, updatedAt?: string | null): Promise<LeaveRequest> {
		const res = await api.rejectRequest(id, comment, updatedAt)
		showSuccess(t('absence', 'Request declined'))
		await sessionStore.refreshSession()
		return res
	}

	/**
	 * @param id the request the sidebar shows, null to close it
	 */
	function select(id: number | null): void {
		selectedId.value = id
	}

	return {
		requests,
		balance,
		loading,
		selectedId,
		loadRequests,
		loadMyBalance,
		createRequest,
		updateRequest,
		cancelRequest,
		approveRequest,
		rejectRequest,
		select,
	}
})
