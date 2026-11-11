<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page">
		<header class="page__header">
			<h2 class="page__title">
				{{ t('absence', 'Sick leave') }}
			</h2>
			<div class="page__tools">
				<NcTextField
					v-model="search"
					:label="t('absence', 'Search employee')"
					class="page__search">
					<template #icon>
						<Magnify :size="18" />
					</template>
				</NcTextField>
				<NcCheckboxRadioSwitch v-model="onlyAffected" type="switch">
					{{ t('absence', 'Only employees with recorded days') }}
				</NcCheckboxRadioSwitch>
				<NcSelect
					v-model="type"
					:options="typeOptions"
					label="label"
					:placeholder="t('absence', 'Sick leave (default)')"
					:aria-label-combobox="t('absence', 'Leave type counted')">
					<template #option="{ icon, label }">
						<span class="opt"><span class="opt__icon">{{ icon }}</span>{{ label }}</span>
					</template>
					<template #selected-option="{ icon, label }">
						<span class="opt"><span class="opt__icon">{{ icon }}</span>{{ label }}</span>
					</template>
				</NcSelect>
				<NcSelect
					v-model="year"
					:options="years"
					:clearable="false"
					:aria-label-combobox="t('absence', 'Year')" />
			</div>
		</header>

		<SkeletonList v-if="loading" :rows="6" />

		<NcEmptyContent
			v-else-if="loadError"
			:name="t('absence', 'Could not load the sick leave overview')"
			:description="t('absence', 'Something went wrong while fetching the figures.')">
			<template #icon>
				<AlertCircleOutline :size="20" />
			</template>
			<template #action>
				<NcButton @click="reload">
					{{ t('absence', 'Try again') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="!types.length"
			:name="t('absence', 'No sick leave type configured')"
			:description="t('absence', 'This overview counts the leave type with the key “sick” unless you pick another one above. Add or enable it in the admin settings to see figures here.')">
			<template #icon>
				<Thermometer :size="20" />
			</template>
		</NcEmptyContent>

		<div v-else class="report">
			<div class="tiles">
				<StatTile
					icon="🤒"
					:value="fmt(totals.days)"
					:label="t('absence', 'days in {year}', { year })"
					:caption="countedLabel"
					accent="var(--color-warning)" />
				<StatTile
					icon="👥"
					:value="totals.affected"
					:label="t('absence', 'employees affected')"
					:caption="t('absence', 'of {total}', { total: totals.employees })"
					accent="var(--color-primary-element)" />
				<StatTile
					icon="📉"
					:value="fmt(averagePerEmployee)"
					:label="t('absence', 'avg. days per employee')"
					:caption="t('absence', 'counting everybody')"
					accent="var(--color-info, var(--color-primary-element))" />
			</div>

			<div v-if="filtered.length" class="table-wrap">
				<table class="tbl">
					<thead>
						<tr>
							<th class="num rank">
								#
							</th>
							<th>{{ t('absence', 'Employee') }}</th>
							<th class="num">
								{{ t('absence', 'Days') }}
							</th>
							<th class="num">
								{{ t('absence', 'Absences') }}
							</th>
							<th class="num">
								{{ t('absence', 'Longest') }}
							</th>
							<th class="num">
								{{ t('absence', 'Most recent') }}
							</th>
							<th class="bar-col" />
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="(row, index) in filtered"
							:key="row.employeeUid"
							class="row"
							:class="{ 'row--drillable': row.days > 0 }"
							@click="openRecords(row)">
							<td class="num rank">
								{{ index + 1 }}
							</td>
							<td>
								<div class="emp">
									<NcAvatar
										:user="row.employeeUid"
										:displayName="row.displayName"
										:size="24"
										hideStatus />
									<!-- The whole row is clickable for the mouse; this button is
									     what makes the drilldown keyboard-reachable. -->
									<button
										v-if="row.days > 0"
										type="button"
										class="emp__link"
										:title="t('absence', 'Show these sick days')"
										@click.stop="openRecords(row)">
										{{ row.displayName }}
									</button>
									<template v-else>
										{{ row.displayName }}
									</template>
								</div>
							</td>
							<td class="num strong">
								{{ fmt(row.days) }}
							</td>
							<td class="num">
								{{ row.episodes || '—' }}
							</td>
							<td class="num">
								{{ row.longestEpisode ? fmt(row.longestEpisode) : '—' }}
							</td>
							<td class="num">
								{{ row.lastDate ? formatDate(row.lastDate) : '—' }}
							</td>
							<td class="bar-col">
								<!-- relative to the worst case, so the column is readable
								     without needing to compare the numbers -->
								<MeterBar
									:value="row.days || 0"
									:max="maxDays"
									:color="barColor"
									:ariaLabel="n('absence', '%n day', '%n days', Math.round(row.days))" />
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<NcEmptyContent
				v-if="!filtered.length"
				:name="search ? t('absence', 'No matches') : t('absence', 'Nothing recorded')"
				:description="search
					? t('absence', 'No employee matches “{query}”.', { query: search })
					: t('absence', 'No days recorded in {year} ({type}).', { type: countedLabel, year })">
				<template #icon>
					<Thermometer :size="20" />
				</template>
			</NcEmptyContent>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { LeaveType, SickLeaveRow, SickLeaveTotals, SickLeaveType } from '../../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, onMounted, ref, shallowRef, watch } from 'vue'
import { useRouter } from 'vue-router'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Thermometer from 'vue-material-design-icons/Thermometer.vue'
import MeterBar from '../../components/MeterBar.vue'
import SkeletonList from '../../components/SkeletonList.vue'
import StatTile from '../../components/StatTile.vue'
import api from '../../services/api.ts'
import { useLeaveTypesStore } from '../../stores/leaveTypes.ts'
import { formatNumber, locale } from '../../utils/locale.ts'

const leaveTypesStore = useLeaveTypesStore()

const router = useRouter()

const y = new Date().getFullYear()

const loading = ref(true)
const loadError = ref(false)
// Bumped on every reload; a response whose seq is stale on return is discarded.
let reloadSeq = 0
const rows = shallowRef<SickLeaveRow[]>([])
const types = ref<SickLeaveType[]>([])
const totals = ref<SickLeaveTotals>({ employees: 0, affected: 0, days: 0, episodes: 0 })
// NcTextField types what it emits as string or number
const search = ref<string | number>('')
const onlyAffected = ref(true)
// null means "let the server pick" — it counts the type keyed "sick".
const type = ref<LeaveType | null>(null)
const year = ref(y)
const years = [y - 2, y - 1, y, y + 1]

// A disabled type can still have history worth reporting on, so offer the
// full list rather than the enabled subset — same reasoning as HrAbsences.
const typeOptions = computed<LeaveType[]>(() => leaveTypesStore.leaveTypes)

/**
 * What the page is actually counting, in the report's own words. Taken from
 * the server's answer rather than from the picker so the labels stay true in
 * the default case too — where nothing is picked and the server resolved the
 * "sick" key on its own.
 */
const countedLabel = computed<string>(() => {
	if (!types.value.length) {
		return t('absence', 'Sick leave')
	}
	return types.value.map((type) => type.label).join(' / ')
})

const filtered = computed<SickLeaveRow[]>(() => {
	const q = String(search.value).trim().toLowerCase()
	return rows.value.filter((row) => {
		if (onlyAffected.value && !row.days) {
			return false
		}
		if (!q) {
			return true
		}
		return row.displayName.toLowerCase().includes(q)
			|| row.employeeUid.toLowerCase().includes(q)
	})
})

/** The worst case in the current list, for scaling the bars. */
const maxDays = computed<number>(() => rows.value.reduce((max, row) => Math.max(max, row.days || 0), 0))

const barColor = computed<string>(() => types.value[0]?.color || 'var(--color-primary-element)')

/**
 * Spread across the whole workforce, not just the people who fell ill —
 * the average over the affected only would climb as fewer people get sick,
 * which is the opposite of what the number is read as meaning.
 */
const averagePerEmployee = computed<number>(() => (totals.value.employees ? totals.value.days / totals.value.employees : 0))

watch(year, () => {
	reload()
})

watch(type, () => {
	reload()
})

onMounted(() => {
	reload()
})

/**
 * A count of days, or a dash without one.
 *
 * @param value the number to format
 */
function fmt(value: number | null | undefined): string {
	return value === null || value === undefined
		? '—'
		: formatNumber(value)
}

/**
 * A day in the account's locale.
 *
 * @param iso the day as 'YYYY-MM-DD'
 */
function formatDate(iso: string): string {
	return new Date(iso + 'T00:00:00').toLocaleDateString(locale(), { dateStyle: 'medium' })
}

/**
 * Drill into the individual records behind a row, where they can be
 * corrected or cancelled. Rows with no sick leave have nothing to show.
 *
 * @param row one employee's aggregated figures
 */
function openRecords(row: SickLeaveRow): void {
	if (!row.days) {
		return
	}
	const onlyType = types.value.length === 1 ? types.value[0] : undefined
	router.push({
		name: 'hr-absences',
		query: {
			employee: row.employeeUid,
			// The report can aggregate several sick types; filter by type only
			// when there is exactly one, so nothing gets hidden.
			...(onlyType ? { type: onlyType.id } : {}),
			year: year.value,
		},
	})
}

/**
 * Loads the report of the chosen year and leave type.
 */
async function reload(): Promise<void> {
	// Guard against overlapping reloads (rapid year/type changes): a slow earlier
	// response must not overwrite the rows of a newer one already on screen.
	const seq = ++reloadSeq
	loading.value = true
	loadError.value = false
	try {
		const report = await api.reportSickLeave(year.value, null, type.value?.id ?? null)
		if (seq !== reloadSeq) {
			return
		}
		rows.value = report.rows ?? []
		types.value = report.types ?? []
		totals.value = report.totals ?? { employees: 0, affected: 0, days: 0, episodes: 0 }
	} catch {
		if (seq === reloadSeq) {
			loadError.value = true
			showError(t('absence', 'Could not load the sick leave overview'))
		}
	} finally {
		if (seq === reloadSeq) {
			loading.value = false
		}
	}
}
</script>

<style lang="scss" scoped>
.report {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.rank {
	width: 3em;
	color: var(--color-text-maxcontrast);
}

.strong {
	font-weight: bold;
}

.opt {
	display: inline-flex;
	align-items: center;
	gap: 8px;

	&__icon {
		font-size: 1.1em;
	}
}

.emp {
	display: inline-flex;
	align-items: center;
	gap: 8px;

	&__link {
		background: none;
		border: none;
		padding: 0;
		font: inherit;
		color: inherit;
		cursor: pointer;
		text-align: start;

		&:hover {
			text-decoration: underline;
		}
	}
}

.row--drillable {
	cursor: pointer;

	&:hover {
		background: var(--color-background-hover);
	}
}

.bar-col {
	width: 20%;
	min-width: 80px;
}
</style>
