/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The form after a save: the server's answer, with what was typed in the fields
 * that were not part of this save and are still unsaved.
 *
 * @param form what the fields hold now
 * @param before the server values the form was built from
 * @param after the server values after the save
 * @param saved the keys this save sent
 */
export function keepUnsaved<T extends Record<string, unknown>>(form: T, before: T, after: T, saved: string[]): T {
	const merged = { ...after }
	for (const key in form) {
		if (!saved.includes(key) && String(form[key]) !== String(before[key])) {
			merged[key] = form[key]
		}
	}
	return merged
}
