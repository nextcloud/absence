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
			<NcButton variant="primary" @click="openRecord">
				<template #icon>
					<ClipboardPlusOutline :size="20" />
				</template>
				{{ t('absence', 'Record absence') }}
			</NcButton>
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
					:active="store.selectedId === r.id"
					@select="store.select($event)" />
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
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import CalendarSearch from 'vue-material-design-icons/CalendarSearch.vue'
import ClipboardPlusOutline from 'vue-material-design-icons/ClipboardPlusOutline.vue'
import RequestListItem from '../../components/RequestListItem.vue'
import SkeletonList from '../../components/SkeletonList.vue'
import api from '../../api.js'
import { statusMeta, store } from '../../store.js'

const PAGE_SIZE = 100

const STATUSES = ['PENDING', 'ESCALATED', 'WITHDRAWAL_PENDING', 'APPROVED', 'REJECTED', 'CANCELLED']

export default {
	name: 'HrAbsences',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		CalendarSearch,
		ClipboardPlusOutline,
		RequestListItem,
		SkeletonList,
	},

	inject: ['absence:openRecord'],

	setup() {
		// Expose the module-level reactive store to the template (Options API).
		return { store }
	},

	data() {
		// Preset the filters from the URL so other views can deep-link here (e.g. the
		// sick-leave report drilling into one employee). Done here rather than in a
		// lifecycle hook so the first load already carries them — otherwise the view
		// would fetch the unfiltered list first and immediately refetch.
		const q = this.$route?.query || {}
		const thisYear = new Date().getFullYear()
		const employee = q.employee
			? { uid: q.employee, displayName: q.employeeName || q.employee }
			: null
		const year = q.year ? Number(q.year) : thisYear
		return {
			loading: true,
			loadingMore: false,
			// employeeUid|typeId => remaining days, for the selected year.
			balances: {},
			rows: [],
			hasMore: false,
			employee,
			employeeOptions: employee ? [employee] : [],
			employeeLoading: false,
			type: q.type ? store.leaveTypes.find((lt) => lt.id === Number(q.type)) || null : null,
			status: STATUSES.includes(q.status) ? { value: q.status, label: statusMeta(q.status).label } : null,
			year: { value: year, label: String(year) },
		}
	},

	computed: {
		typeOptions() {
			// Any type may show up in the history, including disabled ones, so filter
			// against the full list rather than the enabled subset.
			return store.leaveTypes
		},

		statusOptions() {
			return STATUSES.map((value) => ({ value, label: statusMeta(value).label }))
		},

		yearOptions() {
			const thisYear = new Date().getFullYear()
			const years = []
			for (let y = thisYear + 1; y >= thisYear - 4; y--) {
				years.push({ value: y, label: String(y) })
			}
			return [...years, { value: null, label: t('absence', 'All years') }]
		},

		filtersActive() {
			return this.employee !== null
				|| this.type !== null
				|| this.status !== null
				|| this.year.value !== null
		},

		/** The filter set as API query params (without paging). */
		query() {
			const params = { scope: 'hr' }
			if (this.employee) {
				params.employeeUid = this.employee.uid
			}
			if (this.type) {
				params.typeId = this.type.id
			}
			if (this.status) {
				params.status = this.status.value
			}
			if (this.year.value !== null) {
				// The API matches on overlap (end >= from, start <= to), so leave
				// spanning New Year shows up in both years.
				params.from = `${this.year.value}-01-01`
				params.to = `${this.year.value}-12-31`
			}
			return params
		},

		/** Serialised filters — watched so one change means exactly one fetch. */
		queryKey() {
			return JSON.stringify(this.query)
		},
	},

	watch: {
		// Any filter change restarts paging from the top; `immediate` covers the
		// initial load, so there is no separate fetch in mounted().
		queryKey: {
			handler() {
				this.reload()
			},

			immediate: true,
		},
	},

	mounted() {
		window.addEventListener('absence:refresh', this.reload)
	},

	beforeUnmount() {
		window.removeEventListener('absence:refresh', this.reload)
	},

	methods: {
		t,
		n,

		clearFilters() {
			this.employee = null
			this.type = null
			this.status = null
			this.year = { value: null, label: t('absence', 'All years') }
		},

		openRecord() {
			this['absence:openRecord']()
		},

		async onEmployeeSearch(query) {
			if (!query || query.length < 2) {
				return
			}
			this.employeeLoading = true
			try {
				this.employeeOptions = await api.searchUsers(query)
			} catch {
				this.employeeOptions = []
			} finally {
				this.employeeLoading = false
			}
		},

		async reload() {
			this.loading = true
			try {
				this.rows = await this.fetch(0)
				this.hasMore = this.rows.length === PAGE_SIZE
			} catch {
				this.rows = []
				this.hasMore = false
				showError(t('absence', 'Could not load the absences'))
			} finally {
				this.loading = false
			}
			await this.loadBalances()
		},

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
		async loadBalances() {
			this.balances = {}
			if (this.year.value === null) {
				return
			}
			try {
				const report = await api.reportBalances(this.year.value)
				const index = {}
				for (const row of report) {
					if (row.remaining !== null) {
						index[`${row.employeeUid}|${row.typeId}`] = row.remaining
					}
				}
				this.balances = index
			} catch {
				this.balances = {}
			}
		},

		/** @param {object} request one absence row */
		remainingFor(request) {
			const value = this.balances[`${request.employeeUid}|${request.typeId}`]
			return value === undefined ? null : value
		},

		async loadMore() {
			this.loadingMore = true
			try {
				const next = await this.fetch(this.rows.length)
				this.rows = [...this.rows, ...next]
				this.hasMore = next.length === PAGE_SIZE
			} catch {
				showError(t('absence', 'Could not load more absences'))
			} finally {
				this.loadingMore = false
			}
		},

		fetch(offset) {
			return api.listRequests({ ...this.query, limit: PAGE_SIZE, offset })
		},
	},
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
