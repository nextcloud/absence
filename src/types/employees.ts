/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { BalanceRow } from './balances.ts'
import type { UserRef } from './common.ts'

/** A working pattern from a date on, from EmployeeService (psalm type Period). */
export interface ContractPeriod {
	/** 'YYYY-MM-DD' the pattern starts to apply. */
	from: string
	/** ISO weekdays (Mon=1..Sun=7), sorted and unique. */
	weekdays: number[]
	/** Contract percentage 1..100, null when HR entered none. */
	percent: number | null
}

/**
 * Someone's contract, from EmployeeService::getContract() (psalm type Contract).
 * Also the answer of PUT /api/profiles/{uid}/contract.
 */
export interface Contract {
	/** First day of employment 'YYYY-MM-DD', null when HR entered none. */
	start: string | null
	/** Last day of employment 'YYYY-MM-DD', null when open-ended or unknown. */
	end: string | null
	/** By start date. Empty while HR entered no working pattern. */
	periods: ContractPeriod[]
}

/** A period as sent: `percent` may be left out, the server stores null. */
export interface ContractPeriodData {
	/** 'YYYY-MM-DD'. A cleared date goes out as null and is refused with the message to show. */
	from: string | null
	weekdays: number[]
	percent?: number | null
}

/** Body of PUT /api/profiles/{uid}/contract (HR only). It replaces the whole contract. */
export interface ContractUpdateData {
	/** 'YYYY-MM-DD'; null or '' clears it. */
	start?: string | null
	end?: string | null
	periods?: ContractPeriodData[]
}

/** A public holiday on a profile: only the day and its name. */
export interface ProfileHoliday {
	/** 'YYYY-MM-DD'. */
	date: string
	label: string
}

/** The public holidays of the person's region code in the current year. */
export interface ProfileHolidays {
	year: number
	count: number
	/** The next two, by date. */
	upcoming: ProfileHoliday[]
	/** How many are still to come this year, today included. */
	remaining: number
}

export interface ProfileSickLeave {
	year: number
	days: number
	/** Number of sick leave records that start in the year. */
	times: number
}

/** The next approved leave of a counting type that has not ended yet. */
export interface ProfileNextLeave {
	id: number
	startDate: string
	endDate: string
	workingDays: number
}

interface ProfileChangeBase {
	/** Uid of who made the change, null for a change made from occ. */
	actor: string | null
	actorName: string | null
	/** ISO 8601 timestamp. */
	createdAt: string
}

export interface ProfileContractChange extends ProfileChangeBase {
	field: 'contract'
	before: Contract
	after: Contract
}

export interface ProfileRegionChange extends ProfileChangeBase {
	field: 'region'
	/** Region code such as 'DE-BY', '' for none. */
	before: string
	after: string
}

/** One entry of someone's contract and region code history, from EmployeeService::history(). */
export type ProfileChange = ProfileContractChange | ProfileRegionChange

export type ProfileChangeField = ProfileChange['field']

/** GET /api/profiles/{uid}, from ProfileService::get(). Served to the person, their manager and HR. */
export interface Profile {
	uid: string
	displayName: string
	/** Whether the viewer looks at their own profile. */
	self: boolean
	/** True for HR. */
	canEditContract: boolean
	manager: UserRef | null
	/** IANA timezone from the person's Nextcloud settings, '' when unset. */
	timezone: string
	/** Whether the Contacts app is enabled for the viewer, so names can link to it. */
	contacts: boolean
	/** Holiday region code such as 'DE' or 'DE-BY', '' when the person set none. */
	regionCode: string
	holidays: ProfileHolidays
	contract: Contract
	sick: ProfileSickLeave
	nextLeave: ProfileNextLeave | null
	/** Null for a manager: only the person and HR see the history. */
	history: ProfileChange[] | null
}

/** What HR still has to complete for an employee. */
export type EmployeeMissing = 'region' | 'manager' | 'contract'

/** One row of HR's employees list, from EmployeeListService::rows(). */
export interface EmployeeRow {
	uid: string
	displayName: string
	/** '' when the person set none. */
	regionCode: string
	/** A country-only code while that country has regional holidays in the list. */
	regionIncomplete: boolean
	contract: Contract
	/** The working pattern in force today, or the first one while none has started; null without any. */
	current: ContractPeriod | null
	manager: UserRef | null
	/** The balance row of the type keyed 'annual' for the year, null when there is none. */
	annual: BalanceRow | null
	/** The year's rows of the types that count against a balance. */
	balances: BalanceRow[]
	/** Approved sick days that start in the year. */
	sickDays: number
	missing: EmployeeMissing[]
	partTime: boolean
	/** The contract starts or ends in the listed year. */
	startsOrLeaves: boolean
}

/** GET /api/employees (HR only), from EmployeeListService::list(). */
export interface EmployeeList {
	year: number
	/** Gid of the employees group, '' when none is configured. */
	employeesGroup: string
	/** Whether the Contacts app is enabled for the viewer. */
	contacts: boolean
	rows: EmployeeRow[]
}

/** Query of GET /api/employees. Without a year the server takes the current one. */
export interface EmployeeListParams {
	year?: number | null
}

/** A result of GET /api/employees/search (guests excluded). */
export type EmployeeSearchResult = UserRef

/** Query of GET /api/employees/search. */
export interface EmployeeSearchParams {
	search: string
	/** 1..50, 20 by default. */
	limit?: number
}

/** Somebody without a complete holiday region code, from RegionReminderService (psalm type Person). */
export interface RegionReminderPerson {
	uid: string
	name: string
	/** The incomplete code they have, '' for none. */
	code: string
	/** A region of their country that has holidays, null without a code. */
	example: string | null
	/** ISO 8601 timestamp of the last reminder, null when never reminded. */
	remindedAt: string | null
}

/**
 * POST /api/employees/remind (HR only). With dryRun the plan, without it the
 * same shape for what was just sent.
 */
export interface RegionReminder {
	send: RegionReminderPerson[]
	/** Reminded within the last week, so left alone this time. */
	recent: RegionReminderPerson[]
}

/** Body of POST /api/employees/remind. dryRun is true when left out. */
export interface RegionReminderData {
	dryRun?: boolean
}
