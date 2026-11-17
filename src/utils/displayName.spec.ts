/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const searchUsers = vi.fn()
vi.mock('../services/api.ts', () => ({ default: { searchUsers } }))

const { displayNameOf } = await import('./displayName.ts')

describe('displayNameOf', () => {
	beforeEach(() => {
		searchUsers.mockReset()
	})

	it('takes the name of the exact match, not of a looser one', async () => {
		searchUsers.mockResolvedValue([
			{ uid: 'bobby', displayName: 'Bobby Tables' },
			{ uid: 'bob', displayName: 'Bob Keller' },
		])
		await expect(displayNameOf('bob')).resolves.toBe('Bob Keller')
		expect(searchUsers).toHaveBeenCalledWith('bob')
	})

	it('falls back to the user id when the search does not reveal the person', async () => {
		searchUsers.mockResolvedValue([{ uid: 'bobby', displayName: 'Bobby Tables' }])
		await expect(displayNameOf('bob')).resolves.toBe('bob')
	})

	it('falls back to the user id when the search fails', async () => {
		searchUsers.mockRejectedValue(new Error('offline'))
		await expect(displayNameOf('bob')).resolves.toBe('bob')
	})
})
