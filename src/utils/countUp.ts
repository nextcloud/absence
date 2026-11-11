/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const DURATION = 900

/**
 * Count a figure from one value to another over a few frames, easing out.
 * Gives back what stops it: a component that goes away or gets another
 * figure must not keep the old count running.
 *
 * @param from the figure shown now
 * @param to the figure to end on
 * @param onValue gets the figure of each frame, rounded to one decimal, and `to` on the last
 */
export function countUp(from: number, to: number, onValue: (value: number) => void): () => void {
	const start = performance.now()
	let frame = requestAnimationFrame(step)

	/**
	 * One frame of the count
	 *
	 * @param now the time of the frame
	 */
	function step(now: number): void {
		const progress = Math.min(1, (now - start) / DURATION)
		if (progress >= 1) {
			onValue(to)
			return
		}
		const easedOutCubic = 1 - Math.pow(1 - progress, 3)
		onValue(Math.round((from + (to - from) * easedOutCubic) * 10) / 10)
		frame = requestAnimationFrame(step)
	}

	return () => cancelAnimationFrame(frame)
}
