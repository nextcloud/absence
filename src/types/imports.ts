/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ContractPeriod, ContractPeriodData } from './employees.ts'
import type { HolidayDescription } from './holidays.ts'

/**
 * Body of the three JSON imports: POST /api/holidays/import, /api/import/leave
 * and /api/employees/json. `json` is the list as text, so the server can name
 * the line of a mistake.
 */
export interface JsonImportData {
	json: string
	/**
	 * Only check and say what would change. When left out the holiday and leave
	 * imports write (false) but the employees import does not (true): always send it.
	 */
	dryRun?: boolean
}

/** One entry of the list POST /api/holidays/import takes. */
export interface HolidayImportEntry {
	date: string
	region: string
	label: string
	recurring?: boolean
}

/**
 * POST /api/holidays/import (HR only), from HolidayService::plan(): what would
 * be added with dryRun, what was added without. An invalid entry fails the
 * whole import with a 422 that names up to 20 of them, one per line.
 */
export interface HolidayImportResult {
	new: HolidayDescription[]
	/** Entries skipped because the region already has a holiday on that date. */
	existing: number
}

/** One entry of the list POST /api/import/leave takes. */
export interface LeaveImportEntry {
	/** A uid or an e-mail address. */
	user: string
	/** Key of a leave type, 'annual' when left out. */
	type?: string
	/** 'YYYY-MM-DD', like `to`. */
	from: string
	to: string
	workingDays: number
	note?: string
}

export interface LeaveImportPerson {
	uid: string
	name: string
	/** Entries that are added for this person. */
	entries: number
	days: number
	/** Entries skipped as already in the app or repeated in the list. */
	skipped: number
}

export interface LeaveImportError {
	/** 1-based position in the list. */
	entry: number
	/** The `user` of the entry as written, '' when it has none. */
	user: string
	message: string
}

/**
 * POST /api/import/leave (HR only), from LeaveImportService::preview(). With
 * dryRun `errors` lists every invalid entry; without it the server answers 422
 * while there is one, so `errors` is then always empty.
 */
export interface LeaveImportResult {
	entries: number
	days: number
	skipped: number
	/** By name. Includes people whose entries were all skipped. */
	people: LeaveImportPerson[]
	errors: LeaveImportError[]
}

/**
 * One employee in the list GET /api/employees/json exports and
 * POST /api/employees/json takes (EmployeeJsonService, psalm type Entry).
 */
export interface EmployeeJsonEntry {
	/** Uid. */
	user: string
	/** Only there for the reader: ignored on import. */
	name: string
	/** Holiday region code, null for none. */
	region: string | null
	/** 'YYYY-MM-DD', like `end`. */
	start: string | null
	end: string | null
	contract: ContractPeriodData[]
	/** Annual leave base days by year, such as { "2026": 28 }. */
	vacation: Record<string, number>
}

/** GET /api/employees/json (HR only). */
export interface EmployeesJsonExport {
	/** The list of EmployeeJsonEntry as formatted text, ready to edit. */
	json: string
	count: number
}

export interface EmployeeJsonRegionChange {
	field: 'region'
	before: string | null
	after: string | null
}

/** The first or last day of employment, 'YYYY-MM-DD'. */
export interface EmployeeJsonDateChange {
	field: 'start' | 'end'
	before: string | null
	after: string | null
}

export interface EmployeeJsonContractChange {
	field: 'contract'
	before: ContractPeriod[]
	after: ContractPeriod[]
}

/** The annual leave base days of one year. */
export interface EmployeeJsonVacationChange {
	field: 'vacation'
	year: number
	before: number
	after: number
}

export type EmployeeJsonFieldChange = EmployeeJsonRegionChange
	| EmployeeJsonDateChange
	| EmployeeJsonContractChange
	| EmployeeJsonVacationChange

export interface EmployeeJsonChange {
	user: string
	name: string
	/** Line of the entry in the text, null when it could not be located. */
	line: number | null
	fields: EmployeeJsonFieldChange[]
}

export interface EmployeeJsonProblem {
	line: number | null
	/** 1-based position of the entry, null for a problem with the list as a whole. */
	entry: number | null
	message: string
}

/**
 * POST /api/employees/json (HR only), from EmployeeJsonService (psalm type
 * Plan): what would change with dryRun, what changed without. With dryRun a
 * wrong entry empties `changes` and fills `errors`; without it the server
 * answers 422 instead, so `errors` is then always empty.
 */
export interface EmployeesJsonPlan {
	changes: EmployeeJsonChange[]
	/** Employees in the list whose data stays as it is. */
	unchanged: number
	errors: EmployeeJsonProblem[]
}
