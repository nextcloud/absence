/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = {
	getSession: vi.fn(),
	listRequests: vi.fn(),
	getMyBalance: vi.fn(),
	createRequest: vi.fn(),
	approveRequest: vi.fn(),
}
const showError = vi.fn()

vi.mock('../services/api.ts', () => ({ default: api }))
vi.mock('../services/logger.ts', () => ({ logger: { error: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError, showSuccess: vi.fn() }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: vi.fn((app: string, key: string, fallback: unknown) => fallback) }))
vi.mock('@nextcloud/l10n', () => ({ t: (app: string, text: string) => text }))

const { useRequestsStore } = await import('./requests.ts')
const { useSessionStore } = await import('./session.ts')

describe('requests store', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		setActivePinia(createPinia())
	})

	it('keeps the list and says so when a load fails', async () => {
		const store = useRequestsStore()
		api.listRequests.mockResolvedValueOnce([{ id: 1 }])
		await store.loadRequests({ scope: 'mine' })
		expect(store.requests).toEqual([{ id: 1 }])

		api.listRequests.mockRejectedValueOnce(new Error('offline'))
		await store.loadRequests({ scope: 'mine' })
		expect(store.requests).toEqual([{ id: 1 }])
		expect(store.loading).toBe(false)
		expect(showError).toHaveBeenCalledWith('Could not load requests')
	})

	it('does not reject when the balance cannot be read', async () => {
		const store = useRequestsStore()
		api.getMyBalance.mockRejectedValueOnce(new Error('offline'))
		await expect(store.loadMyBalance()).resolves.toBeUndefined()
		expect(store.balance).toEqual({ balances: [] })
		expect(showError).toHaveBeenCalledWith('Could not load your balance')
	})

	it('refreshes the session counts after a decision', async () => {
		const store = useRequestsStore()
		api.approveRequest.mockResolvedValueOnce({ id: 3, status: 'APPROVED' })
		api.getSession.mockResolvedValueOnce({ uid: 'manager', pendingApprovals: 0 })
		await store.approveRequest(3, null, '2026-01-01T00:00:00+00:00')
		expect(api.approveRequest).toHaveBeenCalledWith(3, null, '2026-01-01T00:00:00+00:00')
		expect(useSessionStore().session).toEqual({ uid: 'manager', pendingApprovals: 0 })
	})

	it('keeps the session when refreshing it fails', async () => {
		const session = useSessionStore()
		api.getSession.mockRejectedValueOnce(new Error('offline'))
		await session.refreshSession()
		expect(session.session).toEqual({ uid: null })
	})
})
