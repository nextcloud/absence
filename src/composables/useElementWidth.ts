/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Ref } from 'vue'

import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * The rendered width of an element in px, kept up to date while it is mounted.
 *
 * @param target the element to measure
 * @param fallback the width to assume until it is measured
 */
export function useElementWidth(target: Ref<Element | null>, fallback: number): Ref<number> {
	const width = ref(fallback)
	let observer: ResizeObserver | null = null

	onMounted(() => {
		if (!target.value) {
			return
		}
		observer = new ResizeObserver(([entry]) => {
			const measured = Math.round(entry?.contentRect.width ?? 0)
			if (measured > 0) {
				width.value = measured
			}
		})
		observer.observe(target.value)
	})

	onBeforeUnmount(() => observer?.disconnect())

	return width
}
