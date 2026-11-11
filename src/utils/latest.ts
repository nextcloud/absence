/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * "Latest request wins" for a reload that can be started again before it
 * answered: call the returned function when a load starts, and ask what it
 * gives back before using the answer.
 *
 *     const isLatest = startLoad()
 *     const rows = await api.list()
 *     if (isLatest()) { rows.value = rows }
 */
export function latestOnly(): () => (() => boolean) {
	let current = 0
	return () => {
		const started = ++current
		return () => started === current
	}
}
