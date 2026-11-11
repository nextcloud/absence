/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type {
	Attachment,
	AttachmentDeleted,
	CalendarResponse,
	Contract,
	ContractUpdateData,
	Coverage,
	CoverageScope,
	EmployeeBalance,
	EmployeeBalanceRow,
	EmployeeList,
	EmployeeSearchResult,
	EmployeesJsonExport,
	EmployeesJsonPlan,
	EmployeesPreview,
	EmptyResponse,
	Entitlement,
	EntitlementCreateData,
	EntitlementEvent,
	EntitlementUpdateData,
	Group,
	Holiday,
	HolidayChange,
	HolidayData,
	HolidayImportResult,
	HolidayRecord,
	HolidayRegions,
	Insights,
	LeaveImportResult,
	LeaveRequest,
	LeaveRequestDetail,
	LeaveRequestRecord,
	LeaveType,
	LeaveTypeCreateData,
	LeaveTypeUpdateData,
	PersonalConfig,
	PersonalConfigValues,
	Profile,
	ProRataSuggestion,
	RegionReminder,
	RequestCommentRecord,
	RequestCreateData,
	RequestListParams,
	RequestUpdateData,
	SessionInfo,
	Settings,
	SettingsGroupKind,
	SettingsInput,
	SickLeaveReport,
	Trends,
} from '../types/index.ts'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * @param path the path below the app's root, with its leading slash
 */
function url(path: string): string {
	return generateUrl('/apps/absence' + path)
}

/**
 * @param path the endpoint
 * @param params the query
 */
async function get<T>(path: string, params?: object): Promise<T> {
	return (await axios.get<T>(url(path), { params })).data
}

/**
 * @param path the endpoint
 * @param body the JSON body, or a form
 */
async function post<T>(path: string, body?: object): Promise<T> {
	return (await axios.post<T>(url(path), body)).data
}

/**
 * @param path the endpoint
 * @param body the JSON body
 */
async function put<T>(path: string, body: object): Promise<T> {
	return (await axios.put<T>(url(path), body)).data
}

/**
 * @param path the endpoint
 */
async function remove<T>(path: string): Promise<T> {
	return (await axios.delete<T>(url(path))).data
}

/**
 * @param uid a user id, which may hold a slash
 */
function user(uid: string): string {
	return encodeURIComponent(uid)
}

export default {
	// Session & config
	getSession: () => get<SessionInfo>('/api/session'),
	updatePersonalConfig: (values: PersonalConfigValues) => put<PersonalConfig>('/api/personal/config', { values }),

	// Profiles (§6.4): the person, their manager and HR read, HR edits the contract
	getProfile: (uid: string) => get<Profile>(`/api/profiles/${user(uid)}`),
	updateContract: (uid: string, contract: ContractUpdateData) => put<Contract>(`/api/profiles/${user(uid)}/contract`, contract),
	// App settings page (HR and admins)
	getSettings: () => get<Settings>('/api/settings'),
	updateSettings: (values: SettingsInput) => put<Settings>('/api/settings', { values }),
	// Creates the HR or employees group and chooses it, admins only.
	createSettingsGroup: (kind: SettingsGroupKind) => post<Settings>(`/api/settings/groups/${kind}`),
	// Who leaves and who joins the employees if the employees group becomes `group` ('' = everyone).
	previewEmployeesGroup: (group: string) => get<EmployeesPreview>('/api/settings/employees-preview', { group }),

	// Requests
	listRequests: (params?: RequestListParams) => get<LeaveRequest[]>('/api/requests', params),
	getRequest: (id: number) => get<LeaveRequestDetail>(`/api/requests/${id}`),
	createRequest: (data: RequestCreateData) => post<LeaveRequestRecord>('/api/requests', data),
	updateRequest: (id: number, data: RequestUpdateData) => put<LeaveRequest>(`/api/requests/${id}`, data),
	cancelRequest: (id: number) => post<LeaveRequest>(`/api/requests/${id}/cancel`),
	// `updatedAt` is the request as the decider saw it: the server answers 409 if it changed since
	approveRequest: (id: number, comment?: string | null, updatedAt?: string | null) => post<LeaveRequest>(`/api/requests/${id}/approve`, { comment, updatedAt }),
	rejectRequest: (id: number, comment: string, updatedAt?: string | null) => post<LeaveRequest>(`/api/requests/${id}/reject`, { comment, updatedAt }),
	addComment: (id: number, body: string) => post<RequestCommentRecord>(`/api/requests/${id}/comments`, { body }),

	// Attachments (doctor's notes etc.): HR and the employee only
	uploadAttachment: (id: number, file: File) => {
		const form = new FormData()
		form.append('file', file)
		return post<Attachment>(`/api/requests/${id}/attachments`, form)
	},
	deleteAttachment: (id: number) => remove<AttachmentDeleted>(`/api/attachments/${id}`),
	attachmentUrl: (id: number) => url(`/api/attachments/${id}`),

	// Balances & entitlements
	getMyBalance: (year?: number) => get<EmployeeBalance>('/api/balance', { year }),
	// Balances of the caller's own direct reports (empty for non-managers).
	getTeamBalances: (year: number) => get<EmployeeBalanceRow[]>('/api/team/balances', { year }),
	getEmployeeBalance: (uid: string, year: number) => get<EmployeeBalance>(`/api/employees/${user(uid)}/balance`, { year }),
	listEntitlements: (employeeUid: string, year: number) => get<Entitlement[]>('/api/entitlements', { employeeUid, year }),
	createEntitlement: (data: EntitlementCreateData) => post<Entitlement>('/api/entitlements', data),
	updateEntitlement: (id: number, data: EntitlementUpdateData) => put<Entitlement>(`/api/entitlements/${id}`, data),
	// A changed default can be handed on to the people who had the old one this year.
	entitlementsOnDefault: (days: number) => get<{ year: number, count: number }>('/api/entitlements/on-default', { days }),
	applyDefaultEntitlement: (from: number, to: number) => post<{ affected: number }>('/api/entitlements/apply-default', { from, to }),
	// What the contract would give for the year, for HR to take over or not.
	proRataEntitlement: (employeeUid: string, year: number) => get<ProRataSuggestion>('/api/entitlements/pro-rata', { employeeUid, year }),
	// Who changed an entitlement, which figure, from what to what, and why.
	entitlementHistory: (id: number) => get<EntitlementEvent[]>(`/api/entitlements/${id}/history`),
	// Past leave as approved, HR-recorded leave; with dryRun only the review.
	importLeave: (json: string, dryRun: boolean) => post<LeaveImportResult>('/api/import/leave', { json, dryRun }),

	// Coverage & calendar
	getCoverage: (from: string, to: string, scope: CoverageScope) => get<Coverage>('/api/coverage', { from, to, scope }),
	getCalendar: (from: string, to: string, scope: CoverageScope) => get<CalendarResponse>('/api/calendar', { from, to, scope }),

	// Reference data
	listLeaveTypes: (onlyEnabled: boolean) => get<LeaveType[]>('/api/leave-types', { onlyEnabled }),
	createLeaveType: (data: LeaveTypeCreateData) => post<LeaveType>('/api/leave-types', data),
	updateLeaveType: (id: number, data: LeaveTypeUpdateData) => put<LeaveType>(`/api/leave-types/${id}`, data),

	// Public holidays (§7.2): everyone reads, HR maintains
	listHolidays: (year: number, region?: string | null) => get<Holiday[]>('/api/holidays', { year, region }),
	createHoliday: (data: HolidayData) => post<HolidayRecord>('/api/holidays', data),
	updateHoliday: (id: number, data: HolidayData) => put<HolidayRecord>(`/api/holidays/${id}`, data),
	deleteHoliday: (id: number) => remove<EmptyResponse>(`/api/holidays/${id}`),
	holidayHistory: () => get<HolidayChange[]>('/api/holidays/history'),
	// The days a region code gets: its own, its country's and the everywhere ones
	listApplyingHolidays: (code: string, from: string, to: string) => get<HolidayRecord[]>('/api/holidays/applying', { code, from, to }),
	listHolidayRegions: () => get<HolidayRegions>('/api/holidays/regions'),
	importHolidays: (json: string, dryRun: boolean) => post<HolidayImportResult>('/api/holidays/import', { json, dryRun }),

	// HR's list of every employee with their contract, manager and days of a year
	listEmployees: (year: number) => get<EmployeeList>('/api/employees', { year }),
	// Everybody's region code, dates and contract as JSON, checked (dryRun) or written
	exportEmployeesJson: () => get<EmployeesJsonExport>('/api/employees/json'),
	importEmployeesJson: (json: string, dryRun: boolean) => post<EmployeesJsonPlan>('/api/employees/json', { json, dryRun }),
	// HR reminds the people without a region code; dryRun only says who
	remindMissingRegion: (dryRun: boolean) => post<RegionReminder>('/api/employees/remind', { dryRun }),

	// Employee search for the people pickers. Deliberately not core's autocomplete:
	// only the server can tell a guest account from a colleague, and guests are not
	// employees (§2.2). The endpoint wraps the same collaborator search, so the
	// admin's user-enumeration settings still apply.
	searchUsers: (search: string) => get<EmployeeSearchResult[]>('/api/employees/search', { search }),

	// Group list for the HR report/export filters (HR-only endpoint).
	listGroups: () => get<Group[]>('/api/groups'),

	// Reports & export
	reportBalances: (year: number, group?: string | null) => get<EmployeeBalanceRow[]>('/api/reports/balances', { year, group }),
	reportTrends: (from: string, to: string) => get<Trends>('/api/reports/trends', { from, to }),
	// `typeId` overrides which leave type is counted; omitted, the server falls
	// back to the type keyed "sick".
	reportSickLeave: (year: number, group?: string | null, typeId?: number | null) => get<SickLeaveReport>('/api/reports/sick-leave', { year, group, typeId }),
	reportInsights: (year: number) => get<Insights>('/api/reports/insights', { year }),
	exportRequestsUrl: (from: string, to: string, group?: string | null) => {
		const query = new URLSearchParams({ from, to })
		if (group) {
			query.set('group', group)
		}
		return url('/api/export/requests?' + query.toString())
	},
	exportBalancesUrl: (year: number, group?: string | null) => {
		const query = new URLSearchParams({ year: String(year) })
		if (group) {
			query.set('group', group)
		}
		return url('/api/export/balances?' + query.toString())
	},
}
