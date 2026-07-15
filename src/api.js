/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

const url = (path) => generateUrl('/apps/absence' + path)

export default {
	// Session & config
	getSession: () => axios.get(url('/api/session')).then((r) => r.data),
	getAdminConfig: () => axios.get(url('/api/admin/config')).then((r) => r.data),
	updateAdminConfig: (values) => axios.put(url('/api/admin/config'), { values }).then((r) => r.data),
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

	// Balances & entitlements
	getMyBalance: (year) => axios.get(url('/api/balance'), { params: { year } }).then((r) => r.data),
	getEmployeeBalance: (uid, year) => axios.get(url(`/api/employees/${encodeURIComponent(uid)}/balance`), { params: { year } }).then((r) => r.data),
	listEntitlements: (employeeUid, year) => axios.get(url('/api/entitlements'), { params: { employeeUid, year } }).then((r) => r.data),
	updateEntitlement: (id, data) => axios.put(url(`/api/entitlements/${id}`), data).then((r) => r.data),
	bulkEntitlements: (data) => axios.post(url('/api/entitlements/bulk'), data).then((r) => r.data),

	// Coverage & calendar
	getCoverage: (from, to, scope) => axios.get(url('/api/coverage'), { params: { from, to, scope } }).then((r) => r.data),
	getCalendar: (from, to, scope) => axios.get(url('/api/calendar'), { params: { from, to, scope } }).then((r) => r.data),

	// Reference data
	listLeaveTypes: (onlyEnabled) => axios.get(url('/api/leave-types'), { params: { onlyEnabled } }).then((r) => r.data),
	createLeaveType: (data) => axios.post(url('/api/leave-types'), data).then((r) => r.data),
	updateLeaveType: (id, data) => axios.put(url(`/api/leave-types/${id}`), data).then((r) => r.data),
	deleteLeaveType: (id) => axios.delete(url(`/api/leave-types/${id}`)).then((r) => r.data),

	// User search (HR recording leave for an employee) — core autocomplete API
	searchUsers: (search) => axios.get(generateOcsUrl('core/autocomplete/get'), {
		params: { search, itemType: ' ', itemId: ' ', shareTypes: [0], limit: 20 },
	}).then((r) => (r.data.ocs?.data || []).map((u) => ({ uid: u.id, displayName: u.label }))),

	// Reports & export
	reportBalances: (year, group) => axios.get(url('/api/reports/balances'), { params: { year, group } }).then((r) => r.data),
	reportTrends: (from, to) => axios.get(url('/api/reports/trends'), { params: { from, to } }).then((r) => r.data),
	exportRequestsUrl: (from, to) => url(`/api/export/requests?from=${from}&to=${to}`),
	exportBalancesUrl: (year) => url(`/api/export/balances?year=${year}`),
}
