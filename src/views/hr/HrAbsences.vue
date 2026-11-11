<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - HR's browse-and-correct view (§5.6): every recorded absence, filterable, with
  - the standard request sidebar behind each row. Recording and correcting are the
  - same job at different times, so this sits next to "Record absence" in the nav.
  -
  - There is no delete: correcting a wrong entry cancels it (status CANCELLED), so
  - the row and its history survive for the audit trail (§17).
-->
<template>
	<div class="page page--narrow page--roomy">
		<header class="page__header">
			<h2 class="page__title">
				{{ t('absence', 'Absences') }}
			</h2>
			<div class="page__tools">
				<NcButton v-if="sessionStore.session.isHr" @click="importing = true">
					<template #icon>
						<Upload :size="20" />
					</template>
					{{ t('absence', 'Import leave') }}
				</NcButton>
				<NcButton variant="primary" @click="openRecord">
					<template #icon>
						<ClipboardPlusOutline :size="20" />
					</template>
					{{ t('absence', 'Record absence') }}
				</NcButton>
			</div>
		</header>

		<div class="page__tools filters">
			<!-- eslint-disable @nextcloud/no-deprecated-library-props -- NcSelectUsers migration deferred: needs live-instance testing -->
			<NcSelect
				v-model="employee"
				class="filters__employee"
				:options="employeeOptions"
				:loading="employeeLoading"
				:userSelect="true"
				label="displayName"
				:filterable="false"
				:placeholder="t('absence', 'All employees')"
				:aria-label-combobox="t('absence', 'Employee')"
				@search="onEmployeeSearch" />
			<!-- eslint-enable @nextcloud/no-deprecated-library-props -->

			<NcSelect
				v-model="type"
				class="filters__select"
				:options="typeOptions"
				label="label"
				:placeholder="t('absence', 'All types')"
				:aria-label-combobox="t('absence', 'Leave type')">
				<template #option="{ icon, label }">
					<span class="opt"><span class="opt__icon">{{ icon }}</span>{{ label }}</span>
				</template>
				<template #selected-option="{ icon, label }">
					<span class="opt"><span class="opt__icon">{{ icon }}</span>{{ label }}</span>
				</template>
			</NcSelect>

			<NcSelect
				v-model="status"
				class="filters__select"
				:options="statusOptions"
				label="label"
				:placeholder="t('absence', 'All statuses')"
				:aria-label-combobox="t('absence', 'Status')" />

			<NcSelect
				v-model="year"
				class="filters__year"
				:options="yearOptions"
				label="label"
				:clearable="false"
				:aria-label-combobox="t('absence', 'Year')" />
		</div>

		<SkeletonList v-if="loading" :rows="6" />

		<template v-else-if="rows.length">
			<p class="summary">
				{{ n('absence', '%n absence', '%n absences', rows.length) }}
				<span v-if="hasMore" class="summary__muted">{{ t('absence', '(more available)') }}</span>
			</p>

			<TransitionGroup tag="ul" name="rli" class="list">
				<RequestListItem
					v-for="r in rows"
					:key="r.id"
					:request="r"
					:showEmployee="true"
					:remaining="remainingFor(r)"
					:active="requestsStore.selectedId === r.id"
					@select="requestsStore.select($event)" />
			</TransitionGroup>

			<div v-if="hasMore" class="more">
				<NcButton variant="secondary" :disabled="loadingMore" @click="loadMore">
					<template #icon>
						<NcLoadingIcon v-if="loadingMore" :size="20" />
					</template>
					{{ t('absence', 'Load more') }}
				</NcButton>
			</div>
		</template>

		<NcEmptyContent
			v-else
			:name="t('absence', 'No absences found')"
			:description="filtersActive
				? t('absence', 'Nothing matches these filters. Try widening them.')
				: t('absence', 'Recorded absences appear here — including the ones employees request themselves.')">
			<template #icon>
				<CalendarSearch :size="20" />
			</template>
			<template #action>
				<NcButton v-if="filtersActive" variant="secondary" @click="clearFilters">
					{{ t('absence', 'Clear filters') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<LeaveImportDialog v-if="importing" @close="importing = false" @imported="onImported" />
	</div>
</template>

<script setup lang="ts">
import type { LeaveRequest, LeaveType, RequestListParams, RequestStatus, UserRef } from '../../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, inject, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import CalendarSearch from 'vue-material-design-icons/CalendarSearch.vue'
import ClipboardPlusOutline from 'vue-material-design-icons/ClipboardPlusOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import LeaveImportDialog from '../../components/LeaveImportDialog.vue'
import RequestListItem from '../../components/RequestListItem.vue'
import SkeletonList from '../../components/SkeletonList.vue'
import api from '../../services/api.ts'
import { useLeaveTypesStore } from '../../stores/leaveTypes.ts'
import { useRequestsStore } from '../../stores/requests.ts'
import { useSessionStore } from '../../stores/session.ts'
import { openRecordKey } from '../../utils/injectionKeys.ts'
import { statusMeta } from '../../utils/status.ts'

const sessionStore = useSessionStore()
const requestsStore = useRequestsStore()
const leaveTypesStore = useLeaveTypesStore()

/** An entry of the status filter */
interface StatusOption {
	value: RequestStatus
	label: string
}

/** An entry of the year filter: `value` is null for all years */
interface YearOption {
	value: number | null
	label: string
}

const PAGE_SIZE = 100

const STATUSES: RequestStatus[] = ['PENDING', 'ESCALATED', 'WITHDRAWAL_PENDING', 'APPROVED', 'REJECTED', 'CANCELLED']

const openRecordForm = inject(openRecordKey)

// Preset the filters from the URL so other views can deep-link here (e.g. the
// sick-leave report drilling into one employee). Done here rather than in a
// lifecycle hook so the first load already carries them — otherwise the view
// would fetch the unfiltered list first and immediately refetch.
const q = useRoute().query
const thisYear = new Date().getFullYear()
const presetEmployee: UserRef | null = typeof q.employee === 'string' && q.employee
	? { uid: q.employee, displayName: q.employee }
	: null
const presetYear = q.year ? Number(q.year) : thisYear

const loading = ref(true)
const loadingMore = ref(false)
const importing = ref(false)
// Bumped on every reload; a fetch whose seq is stale on return is discarded.
let reloadSeq = 0
// employeeUid|typeId => remaining days, for the selected year.
const balances = shallowRef<Record<string, number>>({})
const rows = shallowRef<LeaveRequest[]>([])
const hasMore = ref(false)
const employee = shallowRef<UserRef | null>(presetEmployee)
const employeeOptions = shallowRef<UserRef[]>(presetEmployee ? [presetEmployee] : [])
const employeeLoading = ref(false)
const type = ref<LeaveType | null>(q.type ? leaveTypesStore.leaveTypes.find((lt) => lt.id === Number(q.type)) || null : null)
const status = ref<StatusOption | null>(isStatus(q.status) ? { value: q.status, label: statusMeta(q.status).label } : null)
const year = ref<YearOption>({ value: presetYear, label: String(presetYear) })

// Any type may show up in the history, including disabled ones, so filter
// against the full list rather than the enabled subset.
const typeOptions = computed(() => leaveTypesStore.leaveTypes)

const statusOptions = computed<StatusOption[]>(() => STATUSES.map((value) => ({ value, label: statusMeta(value).label })))

const yearOptions = computed<YearOption[]>(() => {
	const years: YearOption[] = []
	for (let y = thisYear + 1; y >= thisYear - 4; y--) {
		years.push({ value: y, label: String(y) })
	}
	return [...years, { value: null, label: t('absence', 'All years') }]
})

const filtersActive = computed(() => employee.value !== null
	|| type.value !== null
	|| status.value !== null
	|| year.value.value !== null)

/** The filter set as API query params (without paging). */
const query = computed<RequestListParams>(() => {
	const params: RequestListParams = { scope: 'hr' }
	if (employee.value) {
		params.employeeUid = employee.value.uid
	}
	if (type.value) {
		params.typeId = type.value.id
	}
	if (status.value) {
		params.status = status.value.value
	}
	if (year.value.value !== null) {
		// The API matches on overlap (end >= from, start <= to), so leave
		// spanning New Year shows up in both years.
		params.from = `${year.value.value}-01-01`
		params.to = `${year.value.value}-12-31`
	}
	return params
})

/** Serialised filters — watched so one change means exactly one fetch. */
const queryKey = computed(() => JSON.stringify(query.value))

// Any filter change restarts paging from the top; `immediate` covers the
// initial load, so there is no separate fetch in mounted().
watch(queryKey, () => {
	reload()
}, { immediate: true })

onMounted(() => {
	window.addEventListener('absence:refresh', reload)
})

onBeforeUnmount(() => {
	window.removeEventListener('absence:refresh', reload)
})

/**
 * Whether a value of the URL names a request status.
 *
 * @param value the query value
 */
function isStatus(value: unknown): value is RequestStatus {
	return STATUSES.some((known) => known === value)
}

/**
 * Empties every filter.
 */
function clearFilters(): void {
	employee.value = null
	type.value = null
	status.value = null
	year.value = { value: null, label: t('absence', 'All years') }
}

/**
 * Opens the form HR records an absence with.
 */
function openRecord(): void {
	openRecordForm?.()
}

/**
 * Closes the import dialog and reloads the list.
 */
function onImported(): void {
	importing.value = false
	reload()
}

/**
 * Searches the employees for the filter.
 *
 * @param search what was typed
 */
async function onEmployeeSearch(search: string): Promise<void> {
	if (!search || search.length < 2) {
		return
	}
	employeeLoading.value = true
	try {
		employeeOptions.value = await api.searchUsers(search)
	} catch {
		employeeOptions.value = []
	} finally {
		employeeLoading.value = false
	}
}

/**
 * Loads the first page of the filtered list, then the balances.
 */
async function reload(): Promise<void> {
	// Guard against overlapping reloads (rapid filter changes): a slow earlier
	// fetch must not overwrite the rows of a newer one that already resolved.
	const seq = ++reloadSeq
	loading.value = true
	try {
		const page = await fetchPage(0)
		if (seq !== reloadSeq) {
			return
		}
		rows.value = page
		hasMore.value = page.length === PAGE_SIZE
		// A link from the sick leave report carries the uid only: the name
		// comes with the rows.
		const first = page[0]
		if (employee.value && employee.value.displayName === employee.value.uid && first) {
			employee.value = { ...employee.value, displayName: first.employeeName }
		}
	} catch {
		if (seq !== reloadSeq) {
			return
		}
		rows.value = []
		hasMore.value = false
		showError(t('absence', 'Could not load the absences'))
	} finally {
		if (seq === reloadSeq) {
			loading.value = false
		}
	}
	if (seq === reloadSeq) {
		await loadBalances()
	}
}

/**
 * Remaining days per employee and leave type, indexed for the rows above.
 *
 * One batched report rather than a lookup per row — the server computes every
 * employee's balances in a fixed number of queries, which a request per
 * visible absence would not be.
 *
 * Only for a single reporting year: "remaining" is a per-year figure, so with
 * the filter on "All years" there is no one answer and the rows simply omit
 * it. Silent on failure — the list is still perfectly usable without it.
 */
async function loadBalances(): Promise<void> {
	balances.value = {}
	if (year.value.value === null) {
		return
	}
	const seq = reloadSeq
	try {
		const report = await api.reportBalances(year.value.value)
		if (seq !== reloadSeq) {
			return
		}
		const index: Record<string, number> = {}
		for (const row of report) {
			if (row.remaining !== null) {
				index[`${row.employeeUid}|${row.typeId}`] = row.remaining
			}
		}
		balances.value = index
	} catch {
		if (seq === reloadSeq) {
			balances.value = {}
		}
	}
}

/**
 * The remaining days to show on a row, null when they are not known.
 *
 * @param request one absence row
 */
function remainingFor(request: LeaveRequest): number | null {
	const value = balances.value[`${request.employeeUid}|${request.typeId}`]
	return value === undefined ? null : value
}

/**
 * Appends the next page of the list.
 */
async function loadMore(): Promise<void> {
	// A page asked for before the filters changed belongs to the old list.
	const seq = reloadSeq
	loadingMore.value = true
	try {
		const next = await fetchPage(rows.value.length)
		if (seq !== reloadSeq) {
			return
		}
		rows.value = [...rows.value, ...next]
		hasMore.value = next.length === PAGE_SIZE
	} catch {
		showError(t('absence', 'Could not load more absences'))
	} finally {
		loadingMore.value = false
	}
}

/**
 * Fetches one page of the filtered list.
 *
 * @param offset how many rows to skip
 */
function fetchPage(offset: number): Promise<LeaveRequest[]> {
	return api.listRequests({ ...query.value, limit: PAGE_SIZE, offset })
}
</script>

<style scoped lang="scss">
.filters {
	&__employee { width: 260px; }
	&__select { width: 200px; }
	&__year { width: 140px; }
}

.summary {
	margin: 0;
	color: var(--color-text-maxcontrast);

	&__muted {
		color: var(--color-text-maxcontrast);
	}
}

.list {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 0;
	margin: 0;
	list-style: none;
}

.more {
	display: flex;
	justify-content: center;
}

.opt {
	display: inline-flex;
	align-items: center;
	gap: 8px;

	&__icon {
		font-size: 1.1em;
	}
}

.rli-enter-active,
.rli-leave-active { transition: opacity 250ms ease, transform 250ms ease; }

.rli-enter-from { opacity: 0; transform: translateY(8px); }

.rli-leave-to { opacity: 0; transform: translateX(-12px); }

.rli-move { transition: transform 250ms ease; }

@media (prefers-reduced-motion: reduce) {
	.rli-enter-active,
	.rli-leave-active,
	.rli-move { transition: none; }
}
</style>
