/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { InitialState, LeaveRequest, LeaveType } from '../types/index.ts'

import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '../services/api.ts'

/** How a leave type shows: the stored type, or a stand-in for a withheld or unknown one */
export type LeaveTypeLook = Pick<LeaveType, 'label' | 'color' | 'icon'> & Partial<LeaveType>

/** The part of a request that tells whether HR recorded it */
type Typed = Pick<LeaveRequest, 'typeId'>

export const useLeaveTypesStore = defineStore('leaveTypes', () => {
	const leaveTypes = ref<LeaveType[]>(loadState<InitialState['leaveTypes'] | null>('absence', 'leaveTypes', []) || [])

	const enabledLeaveTypes = computed(() => leaveTypes.value.filter((type) => type.enabled))

	/** Types an employee may self-request (excludes HR-recorded types like sick leave). */
	const requestableLeaveTypes = computed(() => leaveTypes.value.filter((type) => type.enabled && type.employeeRequestable))

	/**
	 * @param id the id of a leave type, null when the server withheld it
	 */
	function leaveType(id: number | null | undefined): LeaveTypeLook {
	// A null/undefined id means the server withheld the leave type (neutral
	// shared-calendar visibility): show a generic "Absent" marker, not "Unknown".
	// The marker has to say nothing about *why* somebody is away. A palm tree here
	// was not neutral — it read as a holiday, so a colleague on sick leave was
	// shown sunbathing. Withholding the reason and then inventing a cheerful one
	// is worse than either revealing it or saying nothing.
		if (id === null || id === undefined) {
			return { label: t('absence', 'Absent'), color: '#888', icon: '•' }
		}
		return leaveTypes.value.find((type) => type.id === id) || { label: t('absence', 'Unknown'), color: '#888', icon: '❔' }
	}

	/**
	 * True when the type is recorded by HR (e.g. sick leave), not self-requested.
	 *
	 * @param request the request to look at
	 */
	function isHrRecorded(request: Typed): boolean {
		// A withheld type (null: the server kept a confidential category to
		// itself) is by definition HR-recorded — confidential types cannot be
		// self-requested.
		if (request.typeId === null || request.typeId === undefined) {
			return true
		}
		return leaveType(request.typeId).employeeRequestable === false
	}

	/**
	 * Whether to show a status chip. HR-recorded leave (sick) that is approved has no
	 * approval concept, so the "Approved" label is hidden as noise.
	 *
	 * @param request the request to look at
	 */
	function statusVisible(request: Typed & Pick<LeaveRequest, 'status'>): boolean {
		return !(isHrRecorded(request) && request.status === 'APPROVED')
	}

	/**
	 * Reads every leave type again, the disabled ones included.
	 */
	async function loadLeaveTypes(): Promise<void> {
		leaveTypes.value = await api.listLeaveTypes(false)
	}

	return { leaveTypes, enabledLeaveTypes, requestableLeaveTypes, leaveType, isHrRecorded, statusVisible, loadLeaveTypes }
})
