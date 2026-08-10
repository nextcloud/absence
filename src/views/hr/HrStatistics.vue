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
		monthData() {
			return Object.entries(this.trends.byMonth).map(([month, value]) => ({
				label: month.slice(5),
				value,
			}))
		},

		typeData() {
			return this.trends.byType.map((tt) => ({
				label: `${tt.typeIcon || ''} ${tt.typeLabel}`.trim(),
				value: tt.days,
				color: tt.typeColor,
			}))
		},

		perMonthAvg() {
			const months = Object.keys(this.trends.byMonth).length
			return months ? this.trends.total / months : 0
		},

		/**
		 * The peak month, which is the one worth staffing around — an average alone
		 * hides the August everybody disappears in.
		 */
		busiestMonth() {
			const entries = Object.entries(this.trends.byMonth)
			if (!entries.length) {
				return { value: 0, label: '' }
			}
			const [month, value] = entries.reduce((best, e) => (e[1] > best[1] ? e : best))
			return {
				value,
				label: new Date(month + '-01T00:00:00').toLocaleDateString(undefined, { month: 'long', year: 'numeric' }),
			}
		},

		rangeCaption() {
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
		async reload() {
			this.loading = true
			try {
				this.trends = await api.reportTrends(toIso(this.from), toIso(this.to))
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
