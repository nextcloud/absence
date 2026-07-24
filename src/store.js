import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Lightweight reactive store shared across views (no Vuex/Pinia dependency).
 */
import { reactive } from 'vue'
import api from './api.js'

/**
 *
 */
function initialSession() {
	try {
		return loadState('absence', 'session')
	} catch {
		return { uid: null }
	}
}

/**
 *
 */
function initialLeaveTypes() {
	try {
		return loadState('absence', 'leaveTypes') || []
	} catch {
		return []
	}
}

export const store = reactive({
	session: initialSession(),
	leaveTypes: initialLeaveTypes(),
	requests: [],
	balance: { balances: [] },
	loading: false,
	selectedId: null,

	// ---- getters ----
	leaveType(id) {
		// A null/undefined id means the server withheld the leave type (neutral
		// shared-calendar visibility): show a generic "Absent" marker, not "Unknown".
		if (id === null || id === undefined) {
			return { label: t('absence', 'Absent'), color: '#888', icon: '🌴' }
		}
		return this.leaveTypes.find((t) => t.id === id) || { label: t('absence', 'Unknown'), color: '#888', icon: '❔' }
	},
	/**
	 * True when the type is recorded by HR (e.g. sick leave), not self-requested.
	 *
	 * @param request
	 */
	isHrRecorded(request) {
		const type = this.leaveType(request.typeId)
		return type && type.employeeRequestable === false
	},
	/**
	 * Whether to show a status chip. HR-recorded leave (sick) that is approved has no
	 * approval concept, so the "Approved" label is hidden as noise.
	 *
	 * @param request
	 */
	statusVisible(request) {
		return !(this.isHrRecorded(request) && request.status === 'APPROVED')
	},
	get enabledLeaveTypes() {
		return this.leaveTypes.filter((t) => t.enabled)
	},
	/** Types an employee may self-request (excludes HR-recorded types like sick leave). */
	get requestableLeaveTypes() {
		return this.leaveTypes.filter((t) => t.enabled && t.employeeRequestable)
	},

	// ---- actions ----
	async refreshSession() {
		try {
			this.session = await api.getSession()
		} catch (e) {
			console.error('Absence: failed to refresh session', e)
		}
	},

	async loadLeaveTypes() {
		this.leaveTypes = await api.listLeaveTypes(false)
	},

	async loadRequests(params) {
		this.loading = true
		try {
			this.requests = await api.listRequests(params)
		} catch {
			showError(t('absence', 'Could not load requests'))
		} finally {
			this.loading = false
		}
	},

	async loadMyBalance(year) {
		this.balance = await api.getMyBalance(year)
	},

	async createRequest(data) {
		const created = await api.createRequest(data)
		showSuccess(t('absence', 'On its way ✈️'))
		await this.refreshSession()
		return created
	},

	async updateRequest(id, data) {
		const updated = await api.updateRequest(id, data)
		showSuccess(t('absence', 'Request updated'))
		return updated
	},

	async cancelRequest(id) {
		const res = await api.cancelRequest(id)
		showSuccess(t('absence', 'Request cancelled'))
		await this.refreshSession()
		return res
	},

	async approveRequest(id, comment) {
		const res = await api.approveRequest(id, comment)
		await this.refreshSession()
		return res
	},

	async rejectRequest(id, comment) {
		const res = await api.rejectRequest(id, comment)
		showSuccess(t('absence', 'Request declined'))
		await this.refreshSession()
		return res
	},

	select(id) {
		this.selectedId = id
	},
})

/**
 * Visual metadata for a request status (spec §15.4).
 * `text` uses Nextcloud's contrast-optimised *-text variables so labels stay
 * readable; `tint` is the base semantic colour used for the chip background.
 *
 * @param status
 */
export function statusMeta(status) {
	switch (status) {
		case 'PENDING':
			return { label: t('absence', 'Pending'), text: 'var(--color-warning-text)', tint: 'var(--color-warning)', icon: '⏳' }
		case 'ESCALATED':
			return { label: t('absence', 'With HR'), text: 'var(--color-warning-text)', tint: 'var(--color-warning)', icon: '⏫' }
		case 'APPROVED':
			return { label: t('absence', 'Approved'), text: 'var(--color-success-text)', tint: 'var(--color-success)', icon: '✅' }
		case 'REJECTED':
			return { label: t('absence', 'Declined'), text: 'var(--color-error-text)', tint: 'var(--color-error)', icon: '✋' }
		case 'CANCELLED':
			return { label: t('absence', 'Cancelled'), text: 'var(--color-text-maxcontrast)', tint: 'var(--color-text-maxcontrast)', icon: '🚫' }
		case 'WITHDRAWAL_PENDING':
			return { label: t('absence', 'Withdrawal pending'), text: 'var(--color-warning-text)', tint: 'var(--color-warning)', icon: '↩️' }
		default:
			return { label: status, text: 'var(--color-main-text)', tint: 'var(--color-text-maxcontrast)', icon: '•' }
	}
}
