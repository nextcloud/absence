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
							:ariaLabel="t('absence', '{days} of {total} days still available', { days: fmt(row.available), total: fmt(row.entitlement) })" />
						<span class="vacation__days" :class="{ 'vacation__days--significant': row.significant }">
							{{ n('absence', '%n day left', '%n days left', row.available) }}
						</span>
					</li>
				</ol>
			</div>
		</template>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import DonutChart from '../../components/DonutChart.vue'
import LineChart from '../../components/LineChart.vue'
import MeterBar from '../../components/MeterBar.vue'
import SkeletonList from '../../components/SkeletonList.vue'
import StatTile from '../../components/StatTile.vue'
import api from '../../api.js'
import { formatDate, toIso } from '../../utils/dates.js'

// Enough for a decade of monthly points; past this a line chart is unreadable
// anyway and the range is almost certainly a typo.
const MAX_MONTHS = 120

export default {
	name: 'HrStatistics',
	components: { NcAvatar, NcDateTimePickerNative, NcEmptyContent, ChartLine, LineChart, DonutChart, MeterBar, SkeletonList, StatTile },
	data() {
		const now = new Date()
		return {
			loading: true,
			from: new Date(now.getFullYear(), 0, 1),
			to: new Date(now.getFullYear(), 11, 31),
			trends: { byMonth: {}, byType: [], total: 0 },
			sickTotals: { days: 0, employees: 0 },
			balanceReport: [],
		}
	},

	computed: {
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
		monthsInRange() {
			if (!this.from || !this.to || this.from > this.to) {
				return []
			}
			const months = []
			const cursor = new Date(this.from.getFullYear(), this.from.getMonth(), 1)
			const last = new Date(this.to.getFullYear(), this.to.getMonth(), 1)
			// A hand-typed year like 0202 would otherwise ask for tens of thousands of
			// points; the cap keeps a fat-fingered date from freezing the page.
			while (cursor <= last && months.length < MAX_MONTHS) {
				months.push(`${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}`)
				cursor.setMonth(cursor.getMonth() + 1)
			}
			return months
		},

		/** True once the range covers more than one year, when "Jan" stops being unique. */
		spansYears() {
			return new Set(this.monthsInRange.map((month) => month.slice(0, 4))).size > 1
		},

		monthData() {
			return this.monthsInRange.map((month) => ({
				label: this.monthLabel(month, this.spansYears ? { month: 'short', year: '2-digit' } : { month: 'short' }),
				value: this.trends.byMonth[month] ?? 0,
			}))
		},

		typeData() {
			return this.trends.byType.map((tt) => ({
				label: `${tt.typeIcon || ''} ${tt.typeLabel}`.trim(),
				value: tt.days,
				color: tt.typeColor,
			}))
		},

		/** The calendar year the per-year figures describe: the year of the "To" date. */
		statsYear() {
			return this.to ? this.to.getFullYear() : new Date().getFullYear()
		},

		/**
		 * Sick days averaged over *everybody*, not just those who were sick —
		 * the burden across the company, per head and per calendar year.
		 */
		sickAvg() {
			return this.sickTotals.employees ? this.sickTotals.days / this.sickTotals.employees : 0
		},

		/**
		 * Who still has vacation to take, most days first — the list HR scans to
		 * nudge people before the year ends. "Available" (not merely remaining):
		 * days that are neither taken nor booked as pending, i.e. genuinely
		 * unplanned. Rows with more than half the entitlement unplanned are
		 * flagged as significant.
		 */
		vacationRows() {
			return this.balanceReport
				.filter((row) => row.countsAgainstBalance && (row.entitlement ?? 0) > 0 && (row.available ?? 0) > 0)
				.map((row) => ({
					employeeUid: row.employeeUid,
					displayName: row.displayName,
					available: row.available,
					entitlement: row.entitlement,
					significant: row.available >= row.entitlement / 2,
				}))
				.sort((a, b) => b.available - a.available)
				.slice(0, 10)
		},

		/**
		 * The peak month, which is the one worth staffing around — an average alone
		 * hides the August everybody disappears in.
		 */
		busiestMonth() {
			const peak = this.monthsInRange.reduce(
				(best, month) => ((this.trends.byMonth[month] ?? 0) > best.value
					? { month, value: this.trends.byMonth[month] }
					: best),
				{ month: null, value: 0 },
			)
			if (peak.month === null) {
				return { value: 0, label: '' }
			}
			return { value: peak.value, label: this.monthLabel(peak.month, { month: 'long', year: 'numeric' }) }
		},

		rangeCaption() {
			if (!this.from || !this.to) {
				return ''
			}
			return `${formatDate(toIso(this.from))} – ${formatDate(toIso(this.to))}`
		},
	},

	watch: {
		from() { this.reload() },
		to() { this.reload() },
	},

	mounted() {
		this.reload()
	},

	methods: {
		t,
		n,
		fmt(v) { return Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 }) },

		/**
		 * Localised name for a 'YYYY-MM' key.
		 *
		 * @param {string} month 'YYYY-MM'
		 * @param {object} options Intl.DateTimeFormat options
		 * @return {string}
		 */
		monthLabel(month, options) {
			return new Date(month + '-01T00:00:00').toLocaleDateString(undefined, options)
		},

		async reload() {
			// The native date inputs report null when cleared, and every date helper
			// here would throw on it. Nothing to ask the server for either.
			if (!this.from || !this.to) {
				return
			}
			this.loading = true
			try {
				const year = this.to.getFullYear()
				const [trends, sick, balances] = await Promise.all([
					api.reportTrends(toIso(this.from), toIso(this.to)),
					api.reportSickLeave(year),
					api.reportBalances(year),
				])
				this.trends = trends
				this.sickTotals = sick.totals
				this.balanceReport = balances
			} catch (e) {
				// Without this the view kept the previous range's figures on screen
				// with no hint that the new ones never arrived.
				this.trends = { byMonth: {}, byType: [], total: 0 }
				this.sickTotals = { days: 0, employees: 0 }
				this.balanceReport = []
				showError(e.response?.data?.message || t('absence', 'Could not load statistics'))
			} finally {
				this.loading = false
			}
		},
	},
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
