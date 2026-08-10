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
					icon="📊"
					:value="fmt(perMonthAvg)"
					:label="t('absence', 'avg. days per month')"
					:caption="t('absence', 'across the whole company')"
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
		</template>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import DonutChart from '../../components/DonutChart.vue'
import LineChart from '../../components/LineChart.vue'
import SkeletonList from '../../components/SkeletonList.vue'
import StatTile from '../../components/StatTile.vue'
import api from '../../api.js'
import { formatDate, toIso } from '../../utils/dates.js'

// Enough for a decade of monthly points; past this a line chart is unreadable
// anyway and the range is almost certainly a typo.
const MAX_MONTHS = 120

export default {
	name: 'HrStatistics',
	components: { NcDateTimePickerNative, NcEmptyContent, ChartLine, LineChart, DonutChart, SkeletonList, StatTile },
	data() {
		const now = new Date()
		return {
			loading: true,
			from: new Date(now.getFullYear(), 0, 1),
			to: new Date(now.getFullYear(), 11, 31),
			trends: { byMonth: {}, byType: [], total: 0 },
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

		/** Averaged over the months asked about, not the months that happened to be busy. */
		perMonthAvg() {
			const months = this.monthsInRange.length
			return months ? this.trends.total / months : 0
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
				this.trends = await api.reportTrends(toIso(this.from), toIso(this.to))
			} catch (e) {
				// Without this the view kept the previous range's figures on screen
				// with no hint that the new ones never arrived.
				this.trends = { byMonth: {}, byType: [], total: 0 }
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
</style>
