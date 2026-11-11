<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - HR's list of every employee (§13): region code, working days, contract,
  - manager, and the days of the selected year, which HR can also edit as JSON.
  - Managers are kept in Contacts and user management, never here.
-->
<template>
	<div class="page">
		<header class="page__header">
			<div>
				<h2 class="page__title">
					{{ t('absence', 'Employees') }}
				</h2>
				<p class="employees__sub">
					{{ employeesGroup
						? t('absence', 'Members of the group {group}, {year}', { group: employeesGroup, year }, undefined, { escape: false })
						: t('absence', 'Everyone except guest accounts, {year}', { year }) }}
				</p>
			</div>
			<div class="page__tools">
				<NcSelect
					v-model="year"
					:options="years"
					:clearable="false"
					:aria-label-combobox="t('absence', 'Year')" />
				<NcButton v-if="withoutRegion" @click="reminding = true">
					<template #icon>
						<BellRingOutline :size="20" />
					</template>
					{{ n('absence', 'Remind {count} without a complete region code', 'Remind {count} without a complete region code', withoutRegion, { count: withoutRegion }) }}
				</NcButton>
				<NcButton :href="exportUrl">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('absence', 'Export') }}
				</NcButton>
				<NcButton @click="editingJson = true">
					<template #icon>
						<CodeBraces :size="20" />
					</template>
					{{ t('absence', 'Edit as JSON') }}
				</NcButton>
				<a
					v-if="contacts"
					class="employees__link"
					:href="contactsUrl"
					target="_blank"
					rel="noopener noreferrer">{{ t('absence', 'Org chart in Contacts') }} ↗</a>
			</div>
		</header>

		<div class="employees__filters">
			<NcTextField
				:modelValue="search"
				:label="t('absence', 'Search')"
				class="page__search"
				@update:modelValue="search = String($event)">
				<template #icon>
					<Magnify :size="18" />
				</template>
			</NcTextField>
			<div class="employees__chips" role="group" :aria-label="t('absence', 'Show')">
				<NcButton
					v-for="option in filters"
					:key="option.id"
					size="small"
					:pressed="filter === option.id"
					@update:pressed="filter = option.id">
					{{ option.label }}
				</NcButton>
			</div>
		</div>

		<SkeletonList v-if="loading" :rows="6" />

		<template v-else>
			<div class="table-wrap">
				<table class="tbl employees__table">
					<thead>
						<tr>
							<th>{{ t('absence', 'Name') }}</th>
							<th>{{ t('absence', 'Region') }}</th>
							<th>{{ t('absence', 'Works') }}</th>
							<th class="num">
								{{ t('absence', 'Contract') }}
							</th>
							<th>{{ t('absence', 'Since') }}</th>
							<th>{{ t('absence', 'Manager') }}</th>
							<th class="num">
								{{ t('absence', 'Days') }}
							</th>
							<th class="num">
								{{ t('absence', 'Taken') }}
							</th>
							<th class="num">
								{{ t('absence', 'Left') }}
							</th>
							<th class="num">
								{{ t('absence', 'Sick') }}
							</th>
							<th>
								<span class="hidden-visually">{{ t('absence', 'Actions') }}</span>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in shown" :key="row.uid">
							<td>
								<router-link class="employees__name" :to="{ name: 'profile-of', params: { uid: row.uid } }">
									<NcAvatar
										:user="row.uid"
										:displayName="row.displayName"
										:size="28"
										disableMenu
										hideStatus />
									{{ row.displayName }}
								</router-link>
							</td>
							<td>
								<template v-if="row.regionCode">
									<span aria-hidden="true">{{ regionFlag(row.regionCode) }}</span> {{ row.regionCode }}
									<NcChip
										v-if="row.regionIncomplete"
										variant="warning"
										noClose
										:text="t('absence', 'Region needed')" />
								</template>
								<NcChip
									v-else
									variant="warning"
									noClose
									:text="t('absence', 'No region code')" />
							</td>
							<td>
								<template v-if="row.current">
									{{ formatWeekdays(row.current.weekdays) }}
								</template>
								<NcChip
									v-else-if="row.missing.includes('contract')"
									variant="warning"
									noClose
									:text="t('absence', 'No contract data')" />
							</td>
							<td class="num">
								<template v-if="row.current && row.current.percent !== null">
									{{ t('absence', '{percent}%', { percent: row.current.percent }) }}
								</template>
							</td>
							<td>
								{{ since(row.contract.start) }}
								<span v-if="row.contract.end" class="employees__muted">
									{{ t('absence', 'until {date}', { date: formatDate(row.contract.end) }) }}
								</span>
							</td>
							<td>
								<template v-if="row.manager">
									{{ row.manager.displayName }}
								</template>
								<NcChip
									v-else
									variant="warning"
									noClose
									:text="t('absence', 'No manager')" />
							</td>
							<td class="num">
								{{ fmt(row.annual?.entitlement) }}
							</td>
							<td class="num">
								{{ fmt(row.annual?.used) }}
							</td>
							<td class="num lead" :class="leftClass(row.annual)">
								{{ fmt(row.annual?.remaining) }}
							</td>
							<td class="num">
								{{ fmt(row.sickDays) }}
							</td>
							<td>
								<NcActions :ariaLabel="t('absence', 'Actions for {name}', { name: row.displayName }, undefined, { escape: false })">
									<NcActionButton
										v-for="balance in row.balances"
										:key="balance.typeId"
										@click="editing = { row, balance }">
										<template #icon>
											<Pencil :size="20" />
										</template>
										{{ t('absence', 'Edit entitlement: {type}', { type: balance.typeLabel }, undefined, { escape: false }) }}
									</NcActionButton>
								</NcActions>
							</td>
						</tr>
					</tbody>
				</table>
				<NcEmptyContent
					v-if="!filtered.length"
					:name="t('absence', 'No matches')"
					:description="t('absence', 'Nobody matches this search and filter.')">
					<template #icon>
						<AccountGroup :size="20" />
					</template>
				</NcEmptyContent>
			</div>
			<NcButton
				v-if="filtered.length > shown.length"
				class="employees__more"
				@click="limit += PAGE">
				{{ t('absence', 'Load more') }}
			</NcButton>
			<p class="employees__note">
				{{ t('absence', 'Days are the allowance HR sets for each person, plus carry-over and adjustments. Without a manager, a person\'s requests go straight to HR.') }}
			</p>
		</template>

		<EntitlementDialog
			v-if="editing"
			:uid="editing.row.uid"
			:displayName="editing.row.displayName"
			:row="editing.balance"
			:year="year"
			@close="editing = null"
			@saved="onSaved" />
		<EmployeesJsonDialog
			v-if="editingJson"
			@close="editingJson = false"
			@saved="onSaved" />
		<RegionReminderDialog v-if="reminding" @close="reminding = false" />
	</div>
</template>

<script setup lang="ts">
import type { BalanceRow, EmployeeRow } from '../../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, onMounted, ref, shallowRef, watch } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import BellRingOutline from 'vue-material-design-icons/BellRingOutline.vue'
import CodeBraces from 'vue-material-design-icons/CodeBraces.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import EmployeesJsonDialog from '../../components/EmployeesJsonDialog.vue'
import EntitlementDialog from '../../components/EntitlementDialog.vue'
import RegionReminderDialog from '../../components/RegionReminderDialog.vue'
import SkeletonList from '../../components/SkeletonList.vue'
import api from '../../services/api.ts'
import { formatDate } from '../../utils/dates.ts'
import { serverMessage } from '../../utils/errors.ts'
import { latestOnly } from '../../utils/latest.ts'
import { formatNumber } from '../../utils/locale.ts'
import { regionFlag } from '../../utils/regions.ts'
import { formatWeekdays } from '../../utils/weekdays.ts'

type FilterId = 'all' | 'missing' | 'partTime' | 'startsOrLeaves'

/** A chip of the filter row */
interface FilterOption {
	id: FilterId
	label: string
}

/** The balance row whose entitlement is being edited, and whose it is */
interface EditedEntitlement {
	row: EmployeeRow
	balance: BalanceRow
}

/** Rows shown at first and added by "Load more" */
const PAGE = 50

// Below this share of the entitlement, the days left are nearly spent.
const LOW_BALANCE_RATIO = 0.2

const matches: Record<FilterId, (row: EmployeeRow) => boolean> = {
	all: () => true,
	missing: (row) => row.missing.length > 0,
	partTime: (row) => row.partTime,
	startsOrLeaves: (row) => row.startsOrLeaves,
}

const currentYear = new Date().getFullYear()

const loading = ref(true)
const rows = shallowRef<EmployeeRow[]>([])
const employeesGroup = ref('')
const contacts = ref(false)
const search = ref('')
const filter = ref<FilterId>('all')
const limit = ref(PAGE)
const year = ref(currentYear)
const years = [currentYear - 1, currentYear, currentYear + 1]
const editing = ref<EditedEntitlement | null>(null)
const editingJson = ref(false)
const reminding = ref(false)

// Changing the year twice quickly: only the year picked last may land.
const startReload = latestOnly()

const withoutRegion = computed(() => rows.value.filter((row) => row.missing.includes('region')).length)

const filters = computed<FilterOption[]>(() => {
	const count = (test: (row: EmployeeRow) => boolean) => rows.value.filter(test).length
	return [
		{ id: 'all', label: n('absence', 'All {count}', 'All {count}', rows.value.length, { count: rows.value.length }) },
		{ id: 'missing', label: `⚠️ ${n('absence', 'Missing data {count}', 'Missing data {count}', count(matches.missing), { count: count(matches.missing) })}` },
		{ id: 'partTime', label: n('absence', 'Part-time {count}', 'Part-time {count}', count(matches.partTime), { count: count(matches.partTime) }) },
		{ id: 'startsOrLeaves', label: n('absence', 'Starts or leaves in {year} {count}', 'Starts or leaves in {year} {count}', count(matches.startsOrLeaves), { year: year.value, count: count(matches.startsOrLeaves) }) },
	]
})

const filtered = computed(() => {
	const q = search.value.trim().toLowerCase()
	return rows.value.filter((row) => matches[filter.value](row)
		&& (!q || row.displayName.toLowerCase().includes(q) || row.uid.toLowerCase().includes(q)))
})

const shown = computed(() => filtered.value.slice(0, limit.value))

const exportUrl = computed(() => api.exportBalancesUrl(year.value))

const contactsUrl = computed(() => generateUrl('/apps/contacts/'))

watch(year, () => {
	reload()
})

watch(search, () => {
	limit.value = PAGE
})

watch(filter, () => {
	limit.value = PAGE
})

onMounted(() => {
	reload()
})

/**
 * Formats a number of days, '' when there is none.
 *
 * @param v the number of days
 */
function fmt(v: number | null | undefined): string {
	return v === null || v === undefined ? '' : formatNumber(v)
}

/**
 * The year someone started, or the day when it is in the selected year.
 *
 * @param start YYYY-MM-DD
 */
function since(start: string | null): string {
	if (!start) {
		return ''
	}
	return Number(start.slice(0, 4)) === year.value ? formatDate(start) : start.slice(0, 4)
}

/**
 * Red when overdrawn, amber when nearly spent.
 *
 * @param annual the annual balance row
 */
function leftClass(annual: BalanceRow | null): string {
	if (!annual || annual.entitlement === null || !(annual.entitlement > 0)) {
		return ''
	}
	// A missing figure compares like 0, as it always did
	const remaining = annual.remaining ?? 0
	if (remaining < 0) {
		return 'neg'
	}
	return remaining < annual.entitlement * LOW_BALANCE_RATIO ? 'low' : ''
}

/**
 * Loads the employees of the selected year.
 */
async function reload(): Promise<void> {
	const isLatest = startReload()
	loading.value = true
	try {
		const list = await api.listEmployees(year.value)
		if (!isLatest()) {
			return
		}
		rows.value = list.rows
		employeesGroup.value = list.employeesGroup
		contacts.value = list.contacts
	} catch (e) {
		if (isLatest()) {
			showError(serverMessage(e) || t('absence', 'Could not load the employees'))
		}
	} finally {
		if (isLatest()) {
			loading.value = false
		}
	}
}

/**
 * Closes the dialog that saved and reloads the list.
 */
async function onSaved(): Promise<void> {
	editing.value = null
	editingJson.value = false
	await reload()
}
</script>

<style scoped lang="scss">
.employees {
	&__sub {
		margin: 4px 0 0;
		color: var(--color-text-maxcontrast);
	}

	&__link {
		color: var(--color-primary-element);
		font-weight: 600;
	}

	&__filters {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 12px;
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	// Eleven columns: tighter than the shared table so they fit the page
	&__table.tbl {
		th, td {
			padding-inline: 8px;
		}
	}

	&__name {
		display: inline-flex;
		align-items: center;
		gap: 10px;
	}

	&__muted,
	&__note {
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
	}

	&__note {
		margin: 0;
	}

	&__more {
		align-self: center;
	}
}

// Amber, not red: the days left are nearly spent, which is worth noticing but is
// not yet the error that an overdrawn balance is. Qualified with the element so it
// outranks the global `.tbl .lead` colour on the same cell.
td.low {
	color: var(--color-warning);
}
</style>
