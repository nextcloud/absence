/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The form after a save: the server's answer, with what was typed in the fields
 * that were not part of this save and are still unsaved.
 *
 * @param {object} form what the fields hold now
 * @param {object} before the server values the form was built from
 * @param {object} after the server values after the save
 * @param {string[]} saved the keys this save sent
 * @return {object}
 */
export function keepUnsaved(form, before, after, saved) {
	const merged = { ...after }
	for (const key of Object.keys(form)) {
		if (!saved.includes(key) && String(form[key]) !== String(before[key])) {
			merged[key] = form[key]
		}
	}
	return merged
}
