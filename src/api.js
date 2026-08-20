/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (path) => generateUrl('/apps/absence' + path)

export default {
	// Session & config
	getSession: () => axios.get(url('/api/session')).then((r) => r.data),
	getPersonalConfig: () => axios.get(url('/api/personal/config')).then((r) => r.data),
	updatePersonalConfig: (values) => axios.put(url('/api/personal/config'), { values }).then((r) => r.data),

	// Requests
	listRequests: (params) => axios.get(url('/api/requests'), { params }).then((r) => r.data),
	getRequest: (id) => axios.get(url(`/api/requests/${id}`)).then((r) => r.data),
	createRequest: (data) => axios.post(url('/api/requests'), data).then((r) => r.data),
	updateRequest: (id, data) => axios.put(url(`/api/requests/${id}`), data).then((r) => r.data),
	cancelRequest: (id) => axios.post(url(`/api/requests/${id}/cancel`)).then((r) => r.data),
	approveRequest: (id, comment) => axios.post(url(`/api/requests/${id}/approve`), { comment }).then((r) => r.data),
	rejectRequest: (id, comment) => axios.post(url(`/api/requests/${id}/reject`), { comment }).then((r) => r.data),
	addComment: (id, body) => axios.post(url(`/api/requests/${id}/comments`), { body }).then((r) => r.data),

	// Attachments (doctor's notes etc.) — HR and the employee only
	uploadAttachment: (id, file) => {
		const form = new FormData()
		form.append('file', file)
		return axios.post(url(`/api/requests/${id}/attachments`), form).then((r) => r.data)
	},
	deleteAttachment: (id) => axios.delete(url(`/api/attachments/${id}`)).then((r) => r.data),
	attachmentUrl: (id) => url(`/api/attachments/${id}`),

	// Balances & entitlements
	getMyBalance: (year) => axios.get(url('/api/balance'), { params: { year } }).then((r) => r.data),
	// Balances of the caller's own direct reports (empty for non-managers).
	getTeamBalances: (year) => axios.get(url('/api/team/balances'), { params: { year } }).then((r) => r.data),
	getEmployeeBalance: (uid, year) => axios.get(url(`/api/employees/${encodeURIComponent(uid)}/balance`), { params: { year } }).then((r) => r.data),
	listEntitlements: (employeeUid, year) => axios.get(url('/api/entitlements'), { params: { employeeUid, year } }).then((r) => r.data),
	createEntitlement: (data) => axios.post(url('/api/entitlements'), data).then((r) => r.data),
	updateEntitlement: (id, data) => axios.put(url(`/api/entitlements/${id}`), data).then((r) => r.data),
	// Who changed an entitlement, which figure, from what to what, and why.
	entitlementHistory: (id) => axios.get(url(`/api/entitlements/${id}/history`)).then((r) => r.data),
	bulkEntitlements: (data) => axios.post(url('/api/entitlements/bulk'), data).then((r) => r.data),

	// Coverage & calendar
	getCoverage: (from, to, scope) => axios.get(url('/api/coverage'), { params: { from, to, scope } }).then((r) => r.data),
	getCalendar: (from, to, scope) => axios.get(url('/api/calendar'), { params: { from, to, scope } }).then((r) => r.data),

	// Reference data
	listLeaveTypes: (onlyEnabled) => axios.get(url('/api/leave-types'), { params: { onlyEnabled } }).then((r) => r.data),
	createLeaveType: (data) => axios.post(url('/api/leave-types'), data).then((r) => r.data),
	updateLeaveType: (id, data) => axios.put(url(`/api/leave-types/${id}`), data).then((r) => r.data),
	deleteLeaveType: (id) => axios.delete(url(`/api/leave-types/${id}`)).then((r) => r.data),

	// Employee search for the people pickers. Deliberately not core's autocomplete:
	// only the server can tell a guest account from a colleague, and guests are not
	// employees (§2.2). The endpoint wraps the same collaborator search, so the
	// admin's user-enumeration settings still apply.
	searchUsers: (search) => axios.get(url('/api/employees/search'), { params: { search } }).then((r) => r.data),

	// Group list for the HR report/export filters (HR-only endpoint).
	listGroups: () => axios.get(url('/api/groups')).then((r) => r.data),

	// Reports & export
	reportBalances: (year, group) => axios.get(url('/api/reports/balances'), { params: { year, group } }).then((r) => r.data),
	reportTrends: (from, to) => axios.get(url('/api/reports/trends'), { params: { from, to } }).then((r) => r.data),
	// `typeId` overrides which leave type is counted; omitted, the server falls
	// back to the type keyed "sick".
	reportSickLeave: (year, group, typeId) => axios.get(url('/api/reports/sick-leave'), { params: { year, group, typeId } }).then((r) => r.data),
	reportInsights: (year) => axios.get(url('/api/reports/insights'), { params: { year } }).then((r) => r.data),
	exportRequestsUrl: (from, to, group) => {
		const query = new URLSearchParams({ from, to })
		if (group) {
			query.set('group', group)
		}
		return url('/api/export/requests?' + query.toString())
	},
	exportBalancesUrl: (year, group) => {
		const query = new URLSearchParams({ year: String(year) })
		if (group) {
			query.set('group', group)
		}
		return url('/api/export/balances?' + query.toString())
	},
}
