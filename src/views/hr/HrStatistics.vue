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
			<div class="cards">
				<div class="card">
					<span class="card__icon" aria-hidden="true">🏖️</span>
					<span class="card__value">{{ fmt(trends.total) }}</span>
					<span class="card__label">{{ t('absence', 'approved leave days') }}</span>
				</div>
				<div class="card">
					<span class="card__icon" aria-hidden="true">📊</span>
					<span class="card__value">{{ fmt(perMonthAvg) }}</span>
					<span class="card__label">{{ t('absence', 'avg. days per month') }}</span>
				</div>
				<div class="card">
					<span class="card__icon" aria-hidden="true">🗂️</span>
					<span class="card__value">{{ trends.byType.length }}</span>
					<span class="card__label">{{ t('absence', 'leave types used') }}</span>
				</div>
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
				<div class="panel">
					<LineChart :title="t('absence', 'Absence days per month')" :data="monthData" />
				</div>
				<div class="panel">
					<DonutChart :title="t('absence', 'Days by leave type')" :data="typeData" />
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
import api from '../../api.js'
import { toIso } from '../../utils/dates.js'

export default {
	name: 'HrStatistics',
	components: { NcDateTimePickerNative, NcEmptyContent, ChartLine, LineChart, DonutChart, SkeletonList },
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
.page {
	max-width: 900px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline, 4px) * 5);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 5);

	&__header {
		display: flex;
		align-items: flex-end;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
	}

	&__title {
		margin: 0;
		font-size: 1.6rem;
	}
}

.range {
	display: flex;
	gap: 12px;
}

.cards {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
}

.card {
	flex: 1 1 160px;
	display: flex;
	flex-direction: column;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large, 12px);
	padding: calc(var(--default-grid-baseline, 4px) * 4);

	&__icon {
		font-size: 1.4rem;
		margin-bottom: 4px;
	}

	&__value {
		font-size: 2rem;
		font-weight: 700;
		color: var(--color-primary-element);
	}

	&__label {
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
	}
}

.panel {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}
</style>
