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
					{{ t('absence', 'Only employees with sick leave') }}
				</NcCheckboxRadioSwitch>
				<NcSelect
					v-model="year"
					:options="years"
					:clearable="false"
					:aria-label-combobox="t('absence', 'Year')" />
			</div>
		</header>

		<SkeletonList v-if="loading" :rows="6" />

		<NcEmptyContent
			v-else-if="!types.length"
			:name="t('absence', 'No sick leave type configured')"
			:description="t('absence', 'This overview counts the leave type with the key “sick”. Add or enable it in the admin settings to see figures here.')">
			<template #icon>
				<Thermometer :size="20" />
			</template>
		</NcEmptyContent>

		<div v-else class="table-wrap">
			<p class="summary">
				{{
					n('absence',
						'%n day of sick leave in {year}',
						'%n days of sick leave in {year}',
						Math.round(totals.days),
						{ year })
				}}
				·
				{{
					n('absence',
						'%n employee affected',
						'%n employees affected',
						totals.affected)
				}}
				<span class="summary__muted">{{ t('absence', 'of {total}', { total: totals.employees }) }}</span>
			</p>

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
					<tr v-for="(row, index) in filtered" :key="row.employeeUid">
						<td class="num rank">
							{{ index + 1 }}
						</td>
						<td>
							<div class="emp">
								<NcAvatar
									:user="row.employeeUid"
									:displayName="row.displayName"
									:size="24"
									hideStatus /> {{ row.displayName }}
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
							<div
								class="bar"
								:style="{ width: barWidth(row.days), backgroundColor: barColor }"
								:title="n('absence', '%n day', '%n days', Math.round(row.days))" />
						</td>
					</tr>
				</tbody>
			</table>

			<NcEmptyContent
				v-if="!filtered.length"
				:name="search ? t('absence', 'No matches') : t('absence', 'No sick leave recorded')"
				:description="search
					? t('absence', 'No employee matches “{query}”.', { query: search })
					: t('absence', 'Nobody has recorded sick leave in {year}.', { year })">
				<template #icon>
					<Thermometer :size="20" />
				</template>
			</NcEmptyContent>
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Thermometer from 'vue-material-design-icons/Thermometer.vue'
import SkeletonList from '../../components/SkeletonList.vue'
import api from '../../api.js'

export default {
	name: 'HrSickLeave',
	components: { NcAvatar, NcCheckboxRadioSwitch, NcEmptyContent, NcSelect, NcTextField, Magnify, Thermometer, SkeletonList },

	data() {
		const y = new Date().getFullYear()
		return {
			loading: true,
			rows: [],
			types: [],
			totals: { employees: 0, affected: 0, days: 0, episodes: 0 },
			search: '',
			onlyAffected: true,
			year: y,
			years: [y - 2, y - 1, y, y + 1],
		}
	},

	computed: {
		filtered() {
			const q = this.search.trim().toLowerCase()
			return this.rows.filter((row) => {
				if (this.onlyAffected && !row.days) {
					return false
				}
				if (!q) {
					return true
				}
				return row.displayName.toLowerCase().includes(q)
					|| row.employeeUid.toLowerCase().includes(q)
			})
		},

		/** The worst case in the current list, for scaling the bars. */
		maxDays() {
			return this.rows.reduce((max, row) => Math.max(max, row.days || 0), 0)
		},

		barColor() {
			return this.types[0]?.color || 'var(--color-primary-element)'
		},
	},

	watch: {
		year() {
			this.reload()
		},
	},

	mounted() {
		this.reload()
	},

	methods: {
		t,
		n,

		fmt(value) {
			return value === null || value === undefined
				? '—'
				: Number(value).toLocaleString(undefined, { maximumFractionDigits: 1 })
		},

		formatDate(iso) {
			return new Date(iso + 'T00:00:00').toLocaleDateString(undefined, { dateStyle: 'medium' })
		},

		barWidth(days) {
			if (!days || this.maxDays <= 0) {
				return '0'
			}
			return Math.max(2, Math.round((days / this.maxDays) * 100)) + '%'
		},

		async reload() {
			this.loading = true
			try {
				const report = await api.reportSickLeave(this.year)
				this.rows = report.rows ?? []
				this.types = report.types ?? []
				this.totals = report.totals ?? { employees: 0, affected: 0, days: 0, episodes: 0 }
			} catch {
				showError(t('absence', 'Could not load the sick leave overview'))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.summary {
	margin: 0 0 calc(var(--default-grid-baseline) * 2);
	color: var(--color-main-text);
}

.summary__muted {
	color: var(--color-text-maxcontrast);
}

.rank {
	width: 3em;
	color: var(--color-text-maxcontrast);
}

.strong {
	font-weight: bold;
}

.emp {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.bar-col {
	width: 22%;
	min-width: 80px;
}

.bar {
	height: 8px;
	border-radius: var(--border-radius);
	/* a zero-width bar would still show a rounded stub */
	min-width: 0;
}
</style>
