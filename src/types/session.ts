/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** The signed-in user, as built by SessionService::getSessionInfo(). */
export interface Session {
	uid: string
	/** Effective working weekdays as a CSV of ISO weekdays (Mon=1..Sun=7), e.g. "1,2,3,4,5". */
	workWeekdays: string
	/** ISO 3166-1 alpha-2 country for public holidays, or '' when unknown. */
	holidayCountry: string
	/** Holiday region code within the country, or '' for none. */
	holidayRegion: string
	displayName: string
	isHr: boolean
	isManager: boolean
	managerUid: string | null
	managerName: string | null
	/** Requests waiting for this user's decision (0 for non-managers). */
	pendingApprovals: number
	/** Requests escalated to HR (0 for non-HR users). */
	escalatedCount: number
}

/** What the session endpoint returns without a signed-in user. */
export interface AnonymousSession {
	uid: null
}

/** Effective personal settings plus what was detected, from PersonalDefaultsService::resolve(). */
export interface PersonalConfig {
	/** Effective working weekdays as a CSV of ISO weekdays: override, else detected, else Mon-Fri. */
	workWeekdays: string
	/** Effective holiday country: override, else detected, else ''. */
	holidayCountry: string
	holidayRegion: string
	/** Whether the user has filled out their Availability. */
	availabilitySet: boolean
	/** ISO weekdays read from Availability, sorted, or null when it is not set. */
	workWeekdaysDetected: number[] | null
	/** Stored override CSV, '' for none. */
	workWeekdaysOverride: string
	holidayCountryDetected: string | null
	/** Stored override country, '' for none. */
	holidayCountryOverride: string
}

/**
 * Personal overrides to store. Unknown keys are ignored by the server and an
 * empty string clears an override.
 */
export interface PersonalConfigValues {
	work_weekdays?: string
	holiday_country?: string
	holiday_region?: string
}
