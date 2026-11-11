/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** The entitlement dialog's fields as typed */
export interface EntitlementForm {
	baseDays: string | number
	adjustmentDelta: string | number
	adjustmentNote: string
}

/** What saving the entitlement dialog sends */
export interface EntitlementPayload {
	adjustmentNote: string
	baseDays?: number
	adjustmentDelta?: number
}

/**
 * What saving the entitlement dialog sends. The base days go along only when
 * they changed, and the adjustment only when one was entered, so saving one
 * never rewrites the other.
 *
 * @param form the fields as typed
 * @param storedBaseDays the base days before the dialog opened
 * @return null when the base days are not a number
 */
export function entitlementPayload(form: EntitlementForm, storedBaseDays: number): EntitlementPayload | null {
	const baseDays = Number(form.baseDays)
	if (String(form.baseDays).trim() === '' || !Number.isFinite(baseDays)) {
		return null
	}
	const data: EntitlementPayload = { adjustmentNote: form.adjustmentNote }
	if (baseDays !== storedBaseDays) {
		data.baseDays = baseDays
	}
	const delta = Number(form.adjustmentDelta)
	if (form.adjustmentDelta !== '' && !Number.isNaN(delta)) {
		data.adjustmentDelta = delta
	}
	return data
}
