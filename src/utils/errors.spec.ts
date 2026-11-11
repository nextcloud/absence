/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'

// The real package needs a browser. This is the check axios itself does.
vi.mock('@nextcloud/axios', () => ({
	isAxiosError: (payload: unknown) => typeof payload === 'object' && payload !== null && 'isAxiosError' in payload && payload.isAxiosError === true,
}))

const { serverMessage } = await import('./errors.ts')

/**
 * What axios throws for an answer with an error status.
 *
 * @param data the body of the failed answer
 */
function failed(data: unknown): unknown {
	return { isAxiosError: true, response: { status: 422, data } }
}

describe('serverMessage', () => {
	it('reads the message of a refused request', () => {
		expect(serverMessage(failed({ message: 'The end is before the start' }))).toBe('The end is before the start')
	})

	it('is empty for an answer without a message', () => {
		expect(serverMessage(failed({}))).toBe('')
		expect(serverMessage(failed('<html>Bad gateway</html>'))).toBe('')
		expect(serverMessage(failed({ message: { nested: true } }))).toBe('')
	})

	it('is empty for anything that is not a failed request', () => {
		expect(serverMessage(new Error('boom'))).toBe('')
		expect(serverMessage(undefined)).toBe('')
	})
})
