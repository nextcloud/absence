<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page">
		<header class="page__header">
			<h2 class="page__title">
				{{ t('absence', 'Statistics') }}
			</h2>
			<div class="range">
				<NcDateTimePickerNative v-model="from" type="date" :label="t('absence', 'From')" />
				<NcDateTimePickerNative v-model="to" type="date" :label="t('absence', 'To')" />
			</div>
		</header>

		<SkeletonList v-if="loading" :rows="4" />

		<template v-else>
			<div class="tiles">
				<StatTile
					icon="🏖️"
					:value="fmt(trends.total)"
					:label="t('absence', 'approved leave days')"
					:caption="rangeCaption"
					accent="var(--color-success)" />
				<StatTile
					icon="🤒"
					:value="fmt(sickAvg)"
					:label="t('absence', 'avg. sick days per employee')"
					:caption="t('absence', 'calendar year {year}, counting everybody', { year: statsYear })"
					accent="var(--color-primary-element)" />
				<StatTile
					icon="🗓️"
					:value="fmt(busiestMonth.value)"
					:label="t('absence', 'days in the busiest month')"
					:caption="busiestMonth.label"
					accent="var(--color-warning)" />
				<StatTile
					icon="🗂️"
					:value="trends.byType.length"
					:label="t('absence', 'leave types used')"
					accent="var(--color-info, var(--color-primary-element))" />
			</div>

			<NcEmptyContent
				v-if="trends.total === 0"
				:name="t('absence', 'No approved leave in this range')"
				:description="t('absence', 'Pick a wider date range, or check back once leave has been approved.')">
				<template #icon>
					<ChartLine :size="20" />
				</template>
			</NcEmptyContent>
			<template v-else>
				<div class="charts">
					<div class="surface">
						<LineChart :title="t('absence', 'Absence days per month')" :data="monthData" />
					</div>
					<div class="surface">
						<DonutChart :title="t('absence', 'Days by leave type')" :data="typeData" />
					</div>
				</div>
			</template>

			<div v-if="vacationRows.length" class="surface vacation">
				<h3 class="vacation__title">
					{{ t('absence', 'Most vacation still to take ({year})', { year: statsYear }) }}
				</h3>
				<p class="vacation__caption">
					{{ t('absence', 'Days not yet taken or booked — the people to nudge about planning their leave.') }}
				</p>
				<ol class="vacation__list">
					<li v-for="row in vacationRows" :key="row.employeeUid" class="vacation__row">
						<span class="vacation__who">
							<NcAvatar
								:user="row.employeeUid"
								:displayName="row.displayName"
								:size="24"
								hideStatus />
							{{ row.displayName }}
						</span>
						<MeterBar
							class="vacation__bar"
							:value="row.available"
							:max="row.entitlement"
							:color="row.significant ? 'var(--color-warning)' : 'var(--color-success)'"
							:ariaLabel="n('absence', '{days} of {total} day still available', '{days} of {total} days still available', row.entitlement, { days: fmt(row.available), total: fmt(row.entitlement) })" />
						<span class="vacation__days" :class="{ 'vacation__days--significant': row.significant }">
							{{ n('absence', '%n day left', '%n days left', row.available) }}
						</span>
					</li>
				</ol>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { EmployeeBalanceRow, SickLeaveTotals, Trends } from '../../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, onMounted, ref, shallowRef, watch } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import DonutChart from '../../components/DonutChart.vue'
import LineChart from '../../components/LineChart.vue'
import MeterBar from '../../components/MeterBar.vue'
import SkeletonList from '../../components/SkeletonList.vue'
import StatTile from '../../components/StatTile.vue'
import api from '../../services/api.ts'
import { formatDate, toIso } from '../../utils/dates.ts'
import { serverMessage } from '../../utils/errors.ts'
import { formatNumber, locale } from '../../utils/locale.ts'

/** A point of the monthly line chart */
interface MonthPoint {
	label: string
	value: number
}

/** A slice of the leave type donut */
interface TypeSlice {
	label: string
	value: number
	color: string
}

/** Somebody with vacation still to take */
interface VacationRow {
	employeeUid: string
	displayName: string
	available: number
	entitlement: number
	significant: boolean
}

// Enough for a decade of monthly points; past this a line chart is unreadable
// anyway and the range is almost certainly a typo.
const MAX_MONTHS = 120

const now = new Date()

const loading = ref(true)
// Bumped on every reload; a response whose seq is stale on return is discarded.
let reloadSeq = 0
const from = ref<Date | null>(new Date(now.getFullYear(), 0, 1))
const to = ref<Date | null>(new Date(now.getFullYear(), 11, 31))
const trends = ref<Trends>({ byMonth: {}, byType: [], total: 0 })
const sickTotals = ref<Pick<SickLeaveTotals, 'days' | 'employees'>>({ days: 0, employees: 0 })
const balanceReport = shallowRef<EmployeeBalanceRow[]>([])

/**
 * Every month the selected range covers, in order — including the ones with
 * no leave at all.
 *
 * The report only returns months that *have* approved leave, which is the
 * right shape for a sum and the wrong one for everything else here: an
 * average over it divides by the months that happened to be busy, and a line
 * chart drawn from it joins January straight to April as though they were
 * adjacent, hiding the quiet quarter between them.
 */
const monthsInRange = computed<string[]>(() => {
	if (!from.value || !to.value || from.value > to.value) {
		return []
	}
	const months: string[] = []
	const cursor = new Date(from.value.getFullYear(), from.value.getMonth(), 1)
	const last = new Date(to.value.getFullYear(), to.value.getMonth(), 1)
	// A hand-typed year like 0202 would otherwise ask for tens of thousands of
	// points; the cap keeps a fat-fingered date from freezing the page.
	while (cursor <= last && months.length < MAX_MONTHS) {
		months.push(`${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}`)
		cursor.setMonth(cursor.getMonth() + 1)
	}
	return months
})

/** True once the range covers more than one year, when "Jan" stops being unique. */
const spansYears = computed<boolean>(() => new Set(monthsInRange.value.map((month) => month.slice(0, 4))).size > 1)

/** Approved days per 'YYYY-MM'. PHP sends an empty map as `[]`: no month has any then. */
const daysByMonth = computed<Record<string, number>>(() => {
	const { byMonth } = trends.value
	return Array.isArray(byMonth) ? {} : byMonth
})

const monthData = computed<MonthPoint[]>(() => monthsInRange.value.map((month) => ({
	label: monthLabel(month, spansYears.value ? { month: 'short', year: '2-digit' } : { month: 'short' }),
	value: daysByMonth.value[month] ?? 0,
})))

const typeData = computed<TypeSlice[]>(() => trends.value.byType.map((tt) => ({
	label: `${tt.typeIcon || ''} ${tt.typeLabel}`.trim(),
	value: tt.days,
	color: tt.typeColor,
})))

/** The calendar year the per-year figures describe: the year of the "To" date. */
const statsYear = computed<number>(() => (to.value ? to.value.getFullYear() : new Date().getFullYear()))

/**
 * Sick days averaged over *everybody*, not just those who were sick —
 * the burden across the company, per head and per calendar year.
 */
const sickAvg = computed<number>(() => (sickTotals.value.employees ? sickTotals.value.days / sickTotals.value.employees : 0))

/**
 * Who still has vacation to take, most days first — the list HR scans to
 * nudge people before the year ends. "Available" (not merely remaining):
 * days that are neither taken nor booked as pending, i.e. genuinely
 * unplanned. Rows with more than half the entitlement unplanned are
 * flagged as significant.
 */
const vacationRows = computed<VacationRow[]>(() => balanceReport.value
	.flatMap((row) => {
		const available = row.available ?? 0
		const entitlement = row.entitlement ?? 0
		if (!row.countsAgainstBalance || !(entitlement > 0) || !(available > 0)) {
			return []
		}
		return [{
			employeeUid: row.employeeUid,
			displayName: row.displayName,
			available,
			entitlement,
			significant: available >= entitlement / 2,
		}]
	})
	.sort((a, b) => b.available - a.available)
	.slice(0, 10))

/**
 * The peak month, which is the one worth staffing around — an average alone
 * hides the August everybody disappears in.
 */
const busiestMonth = computed<MonthPoint>(() => {
	const peak = monthsInRange.value.reduce<{ month: string | null, value: number }>(
		(best, month) => {
			const value = daysByMonth.value[month] ?? 0
			return value > best.value ? { month, value } : best
		},
		{ month: null, value: 0 },
	)
	if (peak.month === null) {
		return { value: 0, label: '' }
	}
	return { value: peak.value, label: monthLabel(peak.month, { month: 'long', year: 'numeric' }) }
})

const rangeCaption = computed<string>(() => {
	if (!from.value || !to.value) {
		return ''
	}
	return `${formatDate(toIso(from.value))} – ${formatDate(toIso(to.value))}`
})

watch(from, () => {
	reload()
})
watch(to, () => {
	reload()
})

onMounted(() => {
	reload()
})

/**
 * A number with at most one decimal.
 *
 * @param v the number to format
 */
function fmt(v: number): string {
	return formatNumber(v)
}

/**
 * Localised name for a 'YYYY-MM' key.
 *
 * @param month 'YYYY-MM'
 * @param options Intl.DateTimeFormat options
 */
function monthLabel(month: string, options: Intl.DateTimeFormatOptions): string {
	return new Date(month + '-01T00:00:00').toLocaleDateString(locale(), options)
}

/**
 * Loads the figures of the chosen range, and of the year it ends in.
 */
async function reload(): Promise<void> {
	// The native date inputs report null when cleared, and every date helper
	// here would throw on it. Nothing to ask the server for either.
	if (!from.value || !to.value) {
		return
	}
	// Guard against overlapping reloads (rapid from/to changes): a slow earlier
	// response must not overwrite the figures of a newer one already on screen.
	const seq = ++reloadSeq
	loading.value = true
	try {
		const year = to.value.getFullYear()
		const [trendsReport, sick, balances] = await Promise.all([
			api.reportTrends(toIso(from.value), toIso(to.value)),
			api.reportSickLeave(year),
			api.reportBalances(year),
		])
		if (seq !== reloadSeq) {
			return
		}
		trends.value = trendsReport
		sickTotals.value = sick.totals
		balanceReport.value = balances
	} catch (e) {
		if (seq !== reloadSeq) {
			return
		}
		// Without this the view kept the previous range's figures on screen
		// with no hint that the new ones never arrived.
		trends.value = { byMonth: {}, byType: [], total: 0 }
		sickTotals.value = { days: 0, employees: 0 }
		balanceReport.value = []
		showError(serverMessage(e) || t('absence', 'Could not load statistics'))
	} finally {
		if (seq === reloadSeq) {
			loading.value = false
		}
	}
}
</script>

<style scoped lang="scss">

.range {
	display: flex;
	gap: 12px;
}

// Side by side where there is room: the monthly trend and the by-type split are
// read together, and stacking them puts a scroll between two halves of one answer.
.charts {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.vacation {
	margin-top: calc(var(--default-grid-baseline, 4px) * 4);

	&__title {
		margin: 0;
	}

	&__caption {
		margin: 2px 0 12px;
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
	}

	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__row {
		display: grid;
		grid-template-columns: minmax(160px, 1fr) 2fr auto;
		align-items: center;
		gap: 12px;
	}

	&__who {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__days {
		font-variant-numeric: tabular-nums;
		text-align: end;

		&--significant {
			color: var(--color-warning-text);
			font-weight: 600;
		}
	}
}
</style>
