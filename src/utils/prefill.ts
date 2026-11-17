/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { countWorkingDays } from './dates.ts'

/** The part of a saved request the form compares the picked range with */
export interface StoredRange {
	startDate: string
	endDate: string
	workingDays: number
}

/** The state of the request form the prefill looks at */
export interface PrefillForm {
	/** The request being edited, null for a new one */
	request: StoredRange | null
	/** Whether the field was edited by hand */
	touched: boolean
	/** 'YYYY-MM-DD' */
	startIso: string | null
	/** 'YYYY-MM-DD' */
	endIso: string | null
	/** ISO weekdays the person works, null when unknown */
	weekdays: Set<number> | null
	/** Public-holiday predicate */
	isHoliday?: ((iso: string) => boolean) | null
}

/**
 * Whether the picked range is the one the edited request was saved with.
 *
 * @param request the request being edited, null for a new one
 * @param startIso 'YYYY-MM-DD'
 * @param endIso 'YYYY-MM-DD'
 */
export function isStoredRange<T extends Pick<StoredRange, 'startDate' | 'endDate'>>(request: T | null, startIso: string | null, endIso: string | null): request is T {
	return request !== null && request.startDate === startIso && request.endDate === endIso
}

/**
 * What the working-days field of the request form shows once the range, the
 * person or their holidays changed (§7 prefill). A number typed by hand stays,
 * a saved request keeps its count for the range it was saved with, and without
 * known working days the field is left empty instead of guessing.
 *
 * @param form the state of the form
 * @return the value to show, null to leave the field as it is
 */
export function prefilledWorkingDays(form: PrefillForm): string | null {
	const { request, touched, startIso, endIso, weekdays, isHoliday } = form
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

/**
 * The count the dates add up to, when it differs from the number typed by
 * hand, so the form can ask to check it. Null while the prefilled count is
 * shown, when the numbers agree, or when there is nothing to count from.
 *
 * @param form the state of the form
 * @param entered the number in the field
 */
export function estimateMismatch(form: PrefillForm, entered: number): number | null {
	const { touched, startIso, endIso, weekdays, isHoliday } = form
	if (!touched || !startIso || !endIso || !weekdays) {
		return null
	}
	const estimate = countWorkingDays(startIso, endIso, weekdays, isHoliday)
	return estimate === entered ? null : estimate
}

/**
 * The leave type a new request starts on: sick leave when HR records an
 * absence, as that is what they record most, else the first type offered.
 *
 * @param types the types the form offers
 * @param hrMode whether HR records the absence for an employee
 */
export function defaultLeaveType<T extends { key: string }>(types: T[], hrMode: boolean): T | null {
	return (hrMode && types.find((type) => type.key === 'sick')) || types[0] || null
}
