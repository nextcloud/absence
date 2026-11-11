/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { countWorkingDays } from './dates.js'

/**
 * Whether the picked range is the one the edited request was saved with.
 *
 * @param {{startDate: string, endDate: string}|null} request the request being edited, null for a new one
 * @param {string|null} startIso 'YYYY-MM-DD'
 * @param {string|null} endIso 'YYYY-MM-DD'
 * @return {boolean}
 */
export function isStoredRange(request, startIso, endIso) {
	return request !== null && request.startDate === startIso && request.endDate === endIso
}

/**
 * What the working-days field of the request form shows once the range, the
 * person or their holidays changed (§7 prefill). A number typed by hand stays,
 * a saved request keeps its count for the range it was saved with, and without
 * known working days the field is left empty instead of guessing.
 *
 * @param {object} form the state of the form
 * @param {{startDate: string, endDate: string, workingDays: number}|null} form.request the request being edited, null for a new one
 * @param {boolean} form.touched whether the field was edited by hand
 * @param {string|null} form.startIso 'YYYY-MM-DD'
 * @param {string|null} form.endIso 'YYYY-MM-DD'
 * @param {Set<number>|null} form.weekdays ISO weekdays the person works, null when unknown
 * @param {((iso: string) => boolean)|null} [form.isHoliday] public-holiday predicate
 * @return {string|null} the value to show, null to leave the field as it is
 */
export function prefilledWorkingDays({ request, touched, startIso, endIso, weekdays, isHoliday }) {
	if (touched || !startIso || !endIso) {
		return null
	}
	if (isStoredRange(request, startIso, endIso)) {
		return String(request.workingDays)
	}
	if (!weekdays) {
		return ''
	}
	return String(countWorkingDays(startIso, endIso, weekdays, isHoliday))
}
