/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What saving the entitlement dialog sends. The base days go along only when
 * they changed, and the adjustment only when one was entered, so saving one
 * never rewrites the other.
 *
 * @param {{baseDays: string|number, adjustmentDelta: string|number, adjustmentNote: string}} form the fields as typed
 * @param {number} storedBaseDays the base days before the dialog opened
 * @return {{adjustmentNote: string, baseDays?: number, adjustmentDelta?: number}|null} null when the base days are not a number
 */
export function entitlementPayload(form, storedBaseDays) {
	const baseDays = Number(form.baseDays)
	if (String(form.baseDays).trim() === '' || !Number.isFinite(baseDays)) {
		return null
	}
	const data = { adjustmentNote: form.adjustmentNote }
	if (baseDays !== storedBaseDays) {
		data.baseDays = baseDays
	}
	const delta = Number(form.adjustmentDelta)
	if (form.adjustmentDelta !== '' && !Number.isNaN(delta)) {
		data.adjustmentDelta = delta
	}
	return data
}
