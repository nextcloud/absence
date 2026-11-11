/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { LeaveType } from './leaveTypes.ts'

/** The signed-in user, from SessionService::getSessionInfo(): GET /api/session and the 'session' initial state. */
export interface Session {
	uid: string
	displayName: string
	/** The weekdays the person works today as a CSV of ISO weekdays (Mon=1..Sun=7), such as '1,2,3,4,5'. */
	workWeekdays: string
	/** Country half of the holiday region code ('DE'), '' when the person set none. */
	holidayCountry: string
	/** Region half of the holiday region code ('BY'), '' for none. */
	holidayRegion: string
	isHr: boolean
	isAdmin: boolean
	isManager: boolean
	/** False for accounts outside the employees group: the app only says so (§2.2). */
	isEmployee: boolean
	/** False until the HR and employees groups are chosen and exist (§12). */
	configured: boolean
	canEditHolidays: boolean
	/** The setting behind canEditHolidays: everyone, not only HR, may edit the list. */
	holidaysEveryoneEdits: boolean
	/** Link to the handbook for everyone, '' when there is none. */
	handbookUrl: string
	/** Link to the handbook for HR; always '' for anyone else. */
	hrHandbookUrl: string
	managerUid: string | null
	managerName: string | null
	/** Requests of the caller's reports waiting for a decision, 0 for non-managers. */
	pendingApprovals: number
	/** Requests escalated to HR, 0 for anyone but HR. */
	escalatedCount: number
	maxAttachmentBytes: number
}

/**
 * What the session payload is without a signed-in user: only the uid. The other
 * keys are declared as absent so that both sessions can be read the same way.
 */
export interface AnonymousSession extends Partial<Omit<Session, 'uid'>> {
	uid: null
}

export type SessionInfo = Session | AnonymousSession

/**
 * GET and PUT /api/personal/config, from PersonalDefaultsService::resolve():
 * what the request form prefills from.
 */
export interface PersonalConfig {
	/** CSV of ISO weekdays: the contract, else the person's Availability, else Mon-Fri. */
	workWeekdays: string
	holidayCountry: string
	holidayRegion: string
}

/**
 * The personal values that can be stored, by config key. Unknown keys are
 * ignored. The server upper-cases both and answers 422 for anything that
 * cannot be part of a region code.
 */
export interface PersonalConfigValues {
	/** Two letters, '' for none. */
	holiday_country?: string
	/** One to three letters or digits, '' for none. */
	holiday_region?: string
}

/** Body of PUT /api/personal/config. */
export interface PersonalConfigUpdateData {
	values: PersonalConfigValues
}

/** What PageController provides through IInitialState, by key: loadState('absence', key). */
export interface InitialState {
	session: SessionInfo
	leaveTypes: LeaveType[]
}
