<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - HR Insights (§15.7): diagnostic figures beyond the descriptive reports —
  - approval health, the Bradford Factor, leave utilisation and the outstanding
  - leave liability. HR-only; the endpoint enforces it server-side.
-->
<template>
	<div class="page">
		<header class="page__header">
			<h2 class="page__title">
				{{ t('absence', 'Insights') }}
			</h2>
			<label class="year">
				<span class="year__label">{{ t('absence', 'Year') }}</span>
				<select v-model.number="year" class="year__select" @change="reload">
					<option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
				</select>
			</label>
		</header>

		<SkeletonList v-if="loading" :rows="5" />

		<template v-else-if="data">
			<div class="tiles">
				<StatTile
					:value="formatHours(data.approval.medianHours)"
					:label="t('absence', 'median time to a decision')"
					:caption="t('absence', '95th percentile {p}', { p: formatHours(data.approval.p95Hours) })">
					<template #icon>
						<TimerOutline :size="20" />
					</template>
				</StatTile>
				<StatTile
					:value="fmtPct(data.approval.escalationRate)"
					:label="t('absence', 'of requests escalated')"
					:caption="n('absence', '%n request needed a decision', '%n requests needed a decision', data.approval.needingDecision)">
					<template #icon>
						<ArrowUpBoldCircleOutline :size="20" />
					</template>
				</StatTile>
				<StatTile
					:value="fmtPct(data.utilization.company.rate)"
					:label="t('absence', 'annual leave used')"
					:caption="n('absence', '{used} of {total} day', '{used} of {total} days', data.utilization.company.entitlement, { used: fmtDays(data.utilization.company.used), total: fmtDays(data.utilization.company.entitlement) })">
					<template #icon>
						<Leaf :size="20" />
					</template>
				</StatTile>
				<StatTile
					:value="fmtDays(data.liability.outstanding)"
					:label="t('absence', 'days of leave outstanding')"
					:caption="expiryCaption">
					<template #icon>
						<CashMultiple :size="20" />
					</template>
				</StatTile>
			</div>

			<!-- Approval health -->
			<section class="surface">
				<h3 class="surface__title">
					<TimerOutline :size="20" />
					{{ t('absence', 'Approval health') }}
				</h3>
				<p class="surface__lead">
					{{ t('absence', 'How long decisions take, and who is holding requests up. Only leave that goes to a manager is counted.') }}
				</p>
				<table v-if="data.approval.perManager.length" class="grid">
					<thead>
						<tr>
							<th>{{ t('absence', 'Manager') }}</th>
							<th class="num">
								{{ t('absence', 'Decisions') }}
							</th>
							<th class="num">
								{{ t('absence', 'Median') }}
							</th>
							<th class="num">
								{{ t('absence', 'Escalated') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="m in data.approval.perManager" :key="m.managerUid">
							<td>{{ m.name }}</td>
							<td class="num">
								{{ m.decided }}
							</td>
							<td class="num">
								{{ formatHours(m.medianHours) }}
							</td>
							<td class="num">
								<span v-if="m.escalated > 0" class="pill pill--warn">{{ m.escalated }}</span>
								<span v-else class="muted">0</span>
							</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="muted">
					{{ t('absence', 'No decisions recorded yet this year.') }}
				</p>
			</section>

			<!-- Bradford Factor -->
			<section class="surface">
				<h3 class="surface__title">
					<PuzzleOutline :size="20" />
					{{ t('absence', 'Bradford Factor') }}
				</h3>
				<p class="surface__lead">
					{{ t('absence', 'Recorded sick leave scored as spells² × days, so frequent short absences weigh more heavily than one long illness. A prompt for a supportive conversation, not a verdict.') }}
					{{ t('absence', 'A bar fills at {serious}: from {review} a review is due, from {serious} the case is serious.', { review: BRADFORD_REVIEW, serious: BRADFORD_SERIOUS }) }}
				</p>
				<table v-if="data.bradford.length" class="grid">
					<thead>
						<tr>
							<th>{{ t('absence', 'Employee') }}</th>
							<th class="num">
								{{ t('absence', 'Spells') }}
							</th>
							<th class="num">
								{{ t('absence', 'Days') }}
							</th>
							<th class="num">
								{{ t('absence', 'Score') }}
							</th>
							<th class="bar" />
						</tr>
					</thead>
					<tbody>
						<tr v-for="r in data.bradford" :key="r.employeeUid">
							<td>{{ r.name }}</td>
							<td class="num">
								{{ r.spells }}
							</td>
							<td class="num">
								{{ fmtDays(r.days) }}
							</td>
							<td class="num">
								<span class="score">
									<NcChip
										v-if="bradfordLevel(r.score) === 'serious'"
										variant="error"
										noClose
										:text="t('absence', 'Serious')" />
									<NcChip
										v-else-if="bradfordLevel(r.score) === 'review'"
										variant="warning"
										noClose
										:text="t('absence', 'Review due')" />
									<strong>{{ r.score }}</strong>
								</span>
							</td>
							<td class="bar">
								<MeterBar
									:value="r.score"
									:max="BRADFORD_SERIOUS"
									:color="scoreColor(r.score)"
									:ariaLabel="t('absence', 'Score {score} of {serious}', { score: r.score, serious: BRADFORD_SERIOUS })" />
							</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="muted">
					{{ t('absence', 'No sick leave recorded this year. 🎉') }}
				</p>
			</section>

			<!-- Utilisation & well-being -->
			<section class="surface">
				<h3 class="surface__title">
					<Leaf :size="20" />
					{{ t('absence', 'Leave utilisation') }}
				</h3>
				<p class="surface__lead">
					{{ t('absence', 'How much of their annual leave people actually take. Persistently low use is an early burnout signal.') }}
				</p>
				<table v-if="data.utilization.perTeam.length" class="grid">
					<tbody>
						<tr v-for="team in data.utilization.perTeam" :key="team.name">
							<td>{{ team.name }}</td>
							<td class="num">
								{{ fmtPct(team.rate) }}
							</td>
							<td class="bar">
								<MeterBar :value="team.rate" :max="1" :color="rateColor(team.rate)" />
							</td>
						</tr>
					</tbody>
				</table>
				<div v-if="data.utilization.neglected.length" class="watchlist">
					<h4 class="watchlist__title">
						{{ t('absence', 'Not been off in a while') }}
					</h4>
					<ul class="watchlist__list">
						<li v-for="p in data.utilization.neglected" :key="p.employeeUid">
							<span>{{ p.name }}</span>
							<span class="muted">{{ neglectedLabel(p) }}</span>
						</li>
					</ul>
				</div>
			</section>

			<!-- Liability -->
			<section class="surface">
				<h3 class="surface__title">
					<CashMultiple :size="20" />
					{{ t('absence', 'Leave liability') }}
				</h3>
				<p class="surface__lead">
					{{ t('absence', 'Accrued but untaken annual leave (a real cost on the books) and the carry-over about to expire if it is not used.') }}
				</p>
				<div class="liability">
					<div class="liability__figure">
						<span class="liability__value">{{ fmtDays(data.liability.outstanding) }}</span>
						<span class="muted">{{ t('absence', 'days outstanding, company-wide') }}</span>
					</div>
					<div v-if="data.liability.carryOverExposure > 0" class="liability__figure">
						<span class="liability__value liability__value--warn">{{ fmtDays(data.liability.carryOverExposure) }}</span>
						<span class="muted">{{ expiryCaption || t('absence', 'days of carry-over at risk') }}</span>
					</div>
				</div>
				<table v-if="data.liability.perTeam.length" class="grid">
					<tbody>
						<tr v-for="team in data.liability.perTeam" :key="team.name">
							<td>{{ team.name }}</td>
							<td class="num">
								{{ fmtDays(team.outstanding) }}
							</td>
							<td class="bar">
								<MeterBar :value="team.outstanding" :max="liabilityMax" color="var(--color-warning)" />
							</td>
						</tr>
					</tbody>
				</table>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { Insights, NeglectedRow } from '../../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import NcChip from '@nextcloud/vue/components/NcChip'
import ArrowUpBoldCircleOutline from 'vue-material-design-icons/ArrowUpBoldCircleOutline.vue'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import Leaf from 'vue-material-design-icons/Leaf.vue'
import PuzzleOutline from 'vue-material-design-icons/PuzzleOutline.vue'
import TimerOutline from 'vue-material-design-icons/TimerOutline.vue'
import MeterBar from '../../components/MeterBar.vue'
import SkeletonList from '../../components/SkeletonList.vue'
import StatTile from '../../components/StatTile.vue'
import api from '../../services/api.ts'
import { BRADFORD_REVIEW, BRADFORD_SERIOUS, bradfordLevel } from '../../utils/bradford.ts'
import { formatHours } from '../../utils/duration.ts'
import { formatNumber, locale } from '../../utils/locale.ts'

const now = new Date().getFullYear()

const loading = ref(true)
let reloadSeq = 0
const year = ref(now)
const yearOptions = [now + 1, now, now - 1, now - 2, now - 3]
const data = ref<Insights | null>(null)

const liabilityMax = computed<number>(() => Math.max(1, ...(data.value?.liability.perTeam ?? []).map((r) => r.outstanding)))

const expiryCaption = computed<string>(() => {
	const d = data.value?.liability.expiryDate
	if (!d) {
		return ''
	}
	const date = new Date(d + 'T00:00:00').toLocaleDateString(locale(), { day: 'numeric', month: 'short', year: 'numeric' })
	return t('absence', 'carry-over expires {date}', { date })
})

onMounted(() => {
	reload()
	window.addEventListener('absence:refresh', reload)
})

onBeforeUnmount(() => {
	window.removeEventListener('absence:refresh', reload)
})

/**
 * Loads the figures of the chosen year.
 */
async function reload(): Promise<void> {
	// Guard overlapping loads (rapid year changes) — a slow earlier response
	// must not overwrite a newer one already on screen.
	const seq = ++reloadSeq
	loading.value = true
	try {
		const insights = await api.reportInsights(year.value)
		if (seq !== reloadSeq) {
			return
		}
		data.value = insights
	} catch {
		if (seq === reloadSeq) {
			showError(t('absence', 'Could not load insights'))
		}
	} finally {
		if (seq === reloadSeq) {
			loading.value = false
		}
	}
}

/**
 * A share as a whole percentage.
 *
 * @param rate the share, 0..1
 */
function fmtPct(rate: number): string {
	return Math.round((rate || 0) * 100) + '%'
}

/**
 * A count of days.
 *
 * @param v the days
 */
function fmtDays(v: number): string {
	return formatNumber(v || 0)
}

/**
 * How long somebody has not been off.
 *
 * @param p the person
 */
function neglectedLabel(p: NeglectedRow): string {
	if (p.daysSince === null) {
		return t('absence', 'not in the last year')
	}
	return n('absence', '%n day', '%n days', p.daysSince)
}

/**
 * The colour of a Bradford score.
 *
 * @param score the score
 */
function scoreColor(score: number): string {
	switch (bradfordLevel(score)) {
		case 'serious':
			return 'var(--color-element-error)'
		case 'review':
			return 'var(--color-element-warning)'
		default:
			return 'var(--color-primary-element)'
	}
}

/**
 * The colour of a utilisation rate.
 *
 * @param rate the share of leave taken, 0..1
 */
function rateColor(rate: number): string {
	// Low utilisation is the thing to notice, so it reads warm.
	if (rate < 0.4) {
		return 'var(--color-warning, #c98a1e)'
	}
	return 'var(--color-success, #4caf50)'
}
</script>

<style scoped lang="scss">
.year {
	display: flex;
	align-items: center;
	gap: 8px;

	&__label {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small);
	}

	&__select {
		padding: 6px 10px;
		border-radius: var(--border-radius, 8px);
		border: 1px solid var(--color-border);
		background: var(--color-main-background);
		color: var(--color-main-text);
	}
}

.tiles {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
}

.surface {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);

	&__title {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0 0 4px;
		font-size: var(--default-font-size);
	}

	&__lead {
		margin: 0 0 16px;
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small);
		max-width: 60ch;
	}
}

.grid {
	width: 100%;
	border-collapse: collapse;

	th, td {
		padding: 8px 10px;
		text-align: left;
		border-bottom: 1px solid var(--color-border);
	}

	th {
		font-size: var(--font-size-small);
		color: var(--color-text-maxcontrast);
		font-weight: 600;
	}

	.num {
		text-align: right;
		white-space: nowrap;
		font-variant-numeric: tabular-nums;
	}

	.bar {
		width: 30%;
		min-width: 90px;
	}

	tbody tr:last-child td {
		border-bottom: none;
	}
}

.pill {
	display: inline-block;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 999px);
	font-size: var(--font-size-small);

	&--warn {
		background: color-mix(in srgb, var(--color-warning) 22%, transparent);
		color: var(--color-warning-text, var(--color-warning));
	}
}

.muted {
	color: var(--color-text-maxcontrast);
}

.score {
	display: inline-flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.watchlist {
	margin-top: 18px;

	&__title {
		margin: 0 0 8px;
		font-size: var(--default-font-size);
	}

	&__list {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		padding: 0;
		margin: 0;
		list-style: none;

		li {
			display: flex;
			flex-direction: column;
			gap: 2px;
			padding: 8px 12px;
			border-radius: var(--border-radius, 8px);
			background: var(--color-background-hover);
			font-size: var(--font-size-small);
		}
	}
}

.liability {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 6);
	margin-bottom: 16px;

	&__figure {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__value {
		font-size: var(--absence-font-size-figure);
		font-weight: 600;
		line-height: 1.1;

		&--warn {
			color: var(--color-warning-text, var(--color-warning));
		}
	}
}
</style>
