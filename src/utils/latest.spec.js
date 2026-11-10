/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { latestOnly } from './latest.js'

describe('latestOnly', () => {
	it('keeps the answer of a load nothing overtook', () => {
		const start = latestOnly()
		const isLatest = start()
		expect(isLatest()).toBe(true)
	})

	it('drops the answer of a load a newer one overtook', () => {
		const start = latestOnly()
		const first = start()
		const second = start()
		expect(first()).toBe(false)
		expect(second()).toBe(true)
	})

	it('lets a slow first answer land after the second without winning', async () => {
		const start = latestOnly()
		const shown = []
		const load = async (value, delay) => {
			const isLatest = start()
			await new Promise((resolve) => setTimeout(resolve, delay))
			if (isLatest()) {
				shown.push(value)
			}
		}
		await Promise.all([load('January', 20), load('February', 1)])
		expect(shown).toEqual(['February'])
	})

	it('counts each guard on its own', () => {
		const startA = latestOnly()
		const startB = latestOnly()
		const a = startA()
		startB()
		expect(a()).toBe(true)
	})
})
