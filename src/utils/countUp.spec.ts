/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { countUp } from './countUp.ts'

describe('countUp', () => {
	let frames: Map<number, (now: number) => void>
	let nextId: number

	/**
	 * Run the frames waiting at a point in time
	 *
	 * @param now the time the frames get
	 */
	function runFrames(now: number): void {
		const waiting = [...frames.values()]
		frames.clear()
		waiting.forEach((callback) => callback(now))
	}

	beforeEach(() => {
		frames = new Map()
		nextId = 0
		vi.spyOn(performance, 'now').mockReturnValue(0)
		vi.stubGlobal('requestAnimationFrame', (callback: (now: number) => void) => {
			frames.set(++nextId, callback)
			return nextId
		})
		vi.stubGlobal('cancelAnimationFrame', (id: number) => frames.delete(id))
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		vi.restoreAllMocks()
	})

	it('ends on the exact figure and asks for no more frames', () => {
		const values: number[] = []
		countUp(0, 12.5, (value) => values.push(value))
		runFrames(450)
		runFrames(900)
		expect(values).toEqual([10.9, 12.5])
		expect(frames.size).toBe(0)
	})

	it('starts from the figure shown, not from zero', () => {
		const values: number[] = []
		countUp(20, 10, (value) => values.push(value))
		runFrames(0)
		runFrames(900)
		expect(values).toEqual([20, 10])
	})

	it('stops counting once cancelled, also between two frames', () => {
		const values: number[] = []
		const cancel = countUp(0, 30, (value) => values.push(value))
		runFrames(100)
		cancel()
		runFrames(200)
		runFrames(900)
		expect(values).toHaveLength(1)
		expect(frames.size).toBe(0)
	})
})
