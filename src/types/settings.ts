/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Group } from './common.ts'

export type CarryOverPolicy = 'none' | 'capped' | 'unlimited'

/** Whether shared-calendar events name the leave type or only say "Absent". */
export type SharedCalendarVisibility = 'neutral' | 'reveal'

/** The app settings by config key, from ConfigService::getAdminConfig(). */
export interface SettingsValues {
	/** Gid of the group whose members have the HR role. */
	hr_group: string
	/** Gid of the group whose members count as employees, '' while none is chosen. */
	employees_group: string
	/** Annual leave of a full-time year, in working days. */
	default_entitlement: number
	/** Days before an unanswered request is escalated to HR. */
	escalation_window_days: number
	/** Days before escalation that the manager is reminded. */
	reminder_lead_days: number
	carryover_policy: CarryOverPolicy
	/** Most days carried over under the 'capped' policy. */
	carryover_cap: number
	/** 'MM-DD' in the new year when carried-over days expire, '' for never. */
	carryover_expiry: string
	/** Concurrent team absences from which a coverage warning shows, 0 to disable. */
	max_concurrent_absences: number
	/** Calendar days of notice expected for a request, 0 to disable the warning. */
	notice_period_days: number
	caldav_personal: boolean
	caldav_shared: boolean
	holidays_everyone_edits: boolean
	shared_calendar_visibility: SharedCalendarVisibility
	/** A link starting with http(s):// or /, '' for none. */
	handbook_url: string
	hr_handbook_url: string
}

export type SettingsKey = keyof SettingsValues

/**
 * GET and PUT /api/settings and POST /api/settings/groups/{kind}, from
 * SettingsService::get(). HR and admins only.
 */
export interface Settings {
	values: SettingsValues
	/** Every group of the instance, by name. */
	groups: Group[]
	/** Null while no employees group is configured. */
	employeesCount: number | null
}

/**
 * Body of PUT /api/settings: only the keys to change. Only admins may change
 * hr_group. The server takes whole numbers and floats as numeric strings too,
 * but booleans must be booleans.
 */
export interface SettingsUpdateData {
	values: Partial<SettingsValues>
}

/** The {kind} of POST /api/settings/groups/{kind} (admins only): which group to create and choose. */
export type SettingsGroupKind = 'hr' | 'employees'

/** Query of GET /api/settings/employees-preview. */
export interface EmployeesPreviewParams {
	/** Gid of an existing group; the server answers 422 for '' or an unknown one. */
	group: string
}

export interface EmployeesPreviewSide {
	count: number
	/** Display names of the first three, by uid. */
	names: string[]
}

/** GET /api/settings/employees-preview: who leaves and who joins the employees if the group changed. */
export interface EmployeesPreview {
	leaving: EmployeesPreviewSide
	joining: EmployeesPreviewSide
}
