/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { InjectionKey } from 'vue'
import type { LeaveRequest } from '../types/index.ts'

// Provided by App.vue, which owns the request form.

/** Opens the form for a new request */
export const openNewKey: InjectionKey<() => void> = Symbol('absence:openNew')

/** Opens the form HR records an absence with */
export const openRecordKey: InjectionKey<() => void> = Symbol('absence:openRecord')

/** Opens the form on an existing request */
export const openEditKey: InjectionKey<(request: LeaveRequest) => void> = Symbol('absence:openEdit')
