/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type {
	AnonymousSession,
	Attachment,
	CalendarResponse,
	Coverage,
	CoverageScope,
	EmployeeBalance,
	EmployeeBalanceRow,
	EmployeeSearchResult,
	Entitlement,
	EntitlementBulkData,
	EntitlementBulkResult,
	EntitlementCreateData,
	EntitlementEvent,
	EntitlementUpdateData,
	Group,
	Insights,
	LeaveRequest,
	LeaveRequestDetail,
	LeaveRequestRecord,
	LeaveType,
	LeaveTypeCreateData,
	LeaveTypeUpdateData,
	PersonalConfig,
	PersonalConfigValues,
	RequestComment,
	RequestCreateData,
	RequestListParams,
	RequestUpdateData,
	Session,
	SickLeaveReport,
	Trends,
} from './types/index.ts'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (path: string): string => generateUrl('/apps/absence' + path)

export default {
	// Session & config
	getSession: () => axios.get<Session | AnonymousSession>(url('/api/session')).then((r) => r.data),
	getPersonalConfig: () => axios.get<PersonalConfig>(url('/api/personal/config')).then((r) => r.data),
	updatePersonalConfig: (values: PersonalConfigValues) => axios.put<PersonalConfig>(url('/api/personal/config'), { values }).then((r) => r.data),

	// Requests
	listRequests: (params?: RequestListParams) => axios.get<LeaveRequest[]>(url('/api/requests'), { params }).then((r) => r.data),
	getRequest: (id: number) => axios.get<LeaveRequestDetail>(url(`/api/requests/${id}`)).then((r) => r.data),
	createRequest: (data: RequestCreateData) => axios.post<LeaveRequestRecord>(url('/api/requests'), data).then((r) => r.data),
	updateRequest: (id: number, data: RequestUpdateData) => axios.put<LeaveRequest>(url(`/api/requests/${id}`), data).then((r) => r.data),
	cancelRequest: (id: number) => axios.post<LeaveRequest>(url(`/api/requests/${id}/cancel`)).then((r) => r.data),
	approveRequest: (id: number, comment?: string | null) => axios.post<LeaveRequest>(url(`/api/requests/${id}/approve`), { comment }).then((r) => r.data),
	rejectRequest: (id: number, comment: string) => axios.post<LeaveRequest>(url(`/api/requests/${id}/reject`), { comment }).then((r) => r.data),
	addComment: (id: number, body: string) => axios.post<RequestComment>(url(`/api/requests/${id}/comments`), { body }).then((r) => r.data),

	// Attachments (doctor's notes etc.) — HR and the employee only
	uploadAttachment: (id: number, file: File) => {
		const form = new FormData()
		form.append('file', file)
		return axios.post<Attachment>(url(`/api/requests/${id}/attachments`), form).then((r) => r.data)
	},
	deleteAttachment: (id: number) => axios.delete<{ deleted: true }>(url(`/api/attachments/${id}`)).then((r) => r.data),
	attachmentUrl: (id: number) => url(`/api/attachments/${id}`),

	// Balances & entitlements
	getMyBalance: (year?: number | null) => axios.get<EmployeeBalance>(url('/api/balance'), { params: { year } }).then((r) => r.data),
	// Balances of the caller's own direct reports (empty for non-managers).
	getTeamBalances: (year?: number | null) => axios.get<EmployeeBalanceRow[]>(url('/api/team/balances'), { params: { year } }).then((r) => r.data),
	getEmployeeBalance: (uid: string, year?: number | null) => axios.get<EmployeeBalance>(url(`/api/employees/${encodeURIComponent(uid)}/balance`), { params: { year } }).then((r) => r.data),
	listEntitlements: (employeeUid: string, year?: number | null) => axios.get<Entitlement[]>(url('/api/entitlements'), { params: { employeeUid, year } }).then((r) => r.data),
	createEntitlement: (data: EntitlementCreateData) => axios.post<Entitlement>(url('/api/entitlements'), data).then((r) => r.data),
	updateEntitlement: (id: number, data: EntitlementUpdateData) => axios.put<Entitlement>(url(`/api/entitlements/${id}`), data).then((r) => r.data),
	// Who changed an entitlement, which figure, from what to what, and why.
	entitlementHistory: (id: number) => axios.get<EntitlementEvent[]>(url(`/api/entitlements/${id}/history`)).then((r) => r.data),
	bulkEntitlements: (data: EntitlementBulkData) => axios.post<EntitlementBulkResult>(url('/api/entitlements/bulk'), data).then((r) => r.data),

	// Coverage & calendar
	getCoverage: (from: string, to: string, scope?: CoverageScope) => axios.get<Coverage>(url('/api/coverage'), { params: { from, to, scope } }).then((r) => r.data),
	getCalendar: (from: string, to: string, scope?: CoverageScope) => axios.get<CalendarResponse>(url('/api/calendar'), { params: { from, to, scope } }).then((r) => r.data),

	// Reference data
	listLeaveTypes: (onlyEnabled?: boolean) => axios.get<LeaveType[]>(url('/api/leave-types'), { params: { onlyEnabled } }).then((r) => r.data),
	createLeaveType: (data: LeaveTypeCreateData) => axios.post<LeaveType>(url('/api/leave-types'), data).then((r) => r.data),
	updateLeaveType: (id: number, data: LeaveTypeUpdateData) => axios.put<LeaveType>(url(`/api/leave-types/${id}`), data).then((r) => r.data),
	// Soft delete: the type is disabled and returned.
	deleteLeaveType: (id: number) => axios.delete<LeaveType>(url(`/api/leave-types/${id}`)).then((r) => r.data),

	// Employee search for the people pickers. Deliberately not core's autocomplete:
	// only the server can tell a guest account from a colleague, and guests are not
	// employees (§2.2). The endpoint wraps the same collaborator search, so the
	// admin's user-enumeration settings still apply.
	searchUsers: (search: string) => axios.get<EmployeeSearchResult[]>(url('/api/employees/search'), { params: { search } }).then((r) => r.data),

	// Group list for the HR report/export filters (HR-only endpoint).
	listGroups: () => axios.get<Group[]>(url('/api/groups')).then((r) => r.data),

	// Reports & export
	reportBalances: (year?: number | null, group?: string | null) => axios.get<EmployeeBalanceRow[]>(url('/api/reports/balances'), { params: { year, group } }).then((r) => r.data),
	reportTrends: (from: string, to: string) => axios.get<Trends>(url('/api/reports/trends'), { params: { from, to } }).then((r) => r.data),
	// `typeId` overrides which leave type is counted; omitted, the server falls
	// back to the type keyed "sick".
	reportSickLeave: (year?: number | null, group?: string | null, typeId?: number | null) => axios.get<SickLeaveReport>(url('/api/reports/sick-leave'), { params: { year, group, typeId } }).then((r) => r.data),
	reportInsights: (year?: number | null) => axios.get<Insights>(url('/api/reports/insights'), { params: { year } }).then((r) => r.data),
	exportRequestsUrl: (from: string, to: string, group?: string) => {
		const query = new URLSearchParams({ from, to })
		if (group) {
			query.set('group', group)
		}
		return url('/api/export/requests?' + query.toString())
	},
	exportBalancesUrl: (year: number, group?: string) => {
		const query = new URLSearchParams({ year: String(year) })
		if (group) {
			query.set('group', group)
		}
		return url('/api/export/balances?' + query.toString())
	},
}
