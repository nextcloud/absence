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
					icon="⏱️"
					:value="fmtHours(data.approval.medianHours)"
					:label="t('absence', 'median time to a decision')"
					:caption="t('absence', '95th percentile {p}', { p: fmtHours(data.approval.p95Hours) })"
					accent="var(--color-primary-element)" />
				<StatTile
					icon="⏫"
					:value="fmtPct(data.approval.escalationRate)"
					:label="t('absence', 'of requests escalated')"
					:caption="n('absence', '%n request needed a decision', '%n requests needed a decision', data.approval.needingDecision)"
					accent="var(--color-warning)" />
				<StatTile
					icon="🌿"
					:value="fmtPct(data.utilization.company.rate)"
					:label="t('absence', 'annual leave used')"
					:caption="t('absence', '{used} of {total} days', { used: fmtDays(data.utilization.company.used), total: fmtDays(data.utilization.company.entitlement) })"
					accent="var(--color-success)" />
				<StatTile
					icon="💰"
					:value="fmtDays(data.liability.outstanding)"
					:label="t('absence', 'days of leave outstanding')"
					:caption="expiryCaption"
					accent="var(--color-error, var(--color-warning))" />
			</div>

			<!-- Approval health -->
			<section class="surface">
				<h3 class="surface__title">
					⏱️ {{ t('absence', 'Approval health') }}
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
								{{ fmtHours(m.medianHours) }}
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
					🧩 {{ t('absence', 'Bradford Factor') }}
				</h3>
				<p class="surface__lead">
					{{ t('absence', 'Recorded sick leave scored as spells² × days, so frequent short absences weigh more heavily than one long illness. A prompt for a supportive conversation, not a verdict.') }}
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
								<strong>{{ r.score }}</strong>
							</td>
							<td class="bar">
								<MeterBar :value="r.score" :max="bradfordMax" :color="scoreColor(r.score)" />
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
					🌿 {{ t('absence', 'Leave utilisation') }}
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
					💰 {{ t('absence', 'Leave liability') }}
				</h3>
				<p class="surface__lead">
					{{ t('absence', 'Accrued-but-untaken annual leave — a real cost on the books — and the carry-over about to expire if it is not used.') }}
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

<script>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import MeterBar from '../../components/MeterBar.vue'
import SkeletonList from '../../components/SkeletonList.vue'
import StatTile from '../../components/StatTile.vue'
import api from '../../api.js'

export default {
	name: 'HrInsights',
	components: { MeterBar, SkeletonList, StatTile },

	data() {
		const now = new Date().getFullYear()
		return {
			loading: true,
			reloadSeq: 0,
			year: now,
			yearOptions: [now + 1, now, now - 1, now - 2, now - 3],
			data: null,
		}
	},

	computed: {
		bradfordMax() {
			return Math.max(1, ...this.data.bradford.map((r) => r.score))
		},

		liabilityMax() {
			return Math.max(1, ...this.data.liability.perTeam.map((r) => r.outstanding))
		},

		expiryCaption() {
			const d = this.data?.liability.expiryDate
			if (!d) {
				return ''
			}
			const date = new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
			return t('absence', 'carry-over expires {date}', { date })
		},
	},

	mounted() {
		this.reload()
		window.addEventListener('absence:refresh', this.reload)
	},

	beforeUnmount() {
		window.removeEventListener('absence:refresh', this.reload)
	},

	methods: {
		t,
		n,

		async reload() {
			// Guard overlapping loads (rapid year changes) — a slow earlier response
			// must not overwrite a newer one already on screen.
			const seq = ++this.reloadSeq
			this.loading = true
			try {
				const data = await api.reportInsights(this.year)
				if (seq !== this.reloadSeq) {
					return
				}
				this.data = data
			} catch {
				if (seq === this.reloadSeq) {
					showError(t('absence', 'Could not load insights'))
				}
			} finally {
				if (seq === this.reloadSeq) {
					this.loading = false
				}
			}
		},

		fmtHours(h) {
			if (h === null || h === undefined) {
				return '—'
			}
			if (h < 48) {
				return Math.round(h) + 'h'
			}
			return (Math.round(h / 24 * 10) / 10).toLocaleString() + ' ' + t('absence', 'days')
		},

		fmtPct(rate) {
			return Math.round((rate || 0) * 100) + '%'
		},

		fmtDays(v) {
			return Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 1 })
		},

		neglectedLabel(p) {
			if (p.daysSince === null) {
				return t('absence', 'not in the last year')
			}
			return n('absence', '%n day', '%n days', p.daysSince)
		},

		scoreColor(score) {
			// The recognised Bradford thresholds: 500 = review, 900 = serious.
			if (score >= 900) {
				return 'var(--color-error, #d65a5a)'
			}
			if (score >= 500) {
				return 'var(--color-warning, #c98a1e)'
			}
			return 'var(--color-primary-element)'
		},

		rateColor(rate) {
			// Low utilisation is the thing to notice, so it reads warm.
			if (rate < 0.4) {
				return 'var(--color-warning, #c98a1e)'
			}
			return 'var(--color-success, #4caf50)'
		},
	},
}
</script>

<style scoped lang="scss">
.year {
	display: flex;
	align-items: center;
	gap: 8px;

	&__label {
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
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
		margin: 0 0 4px;
		font-size: 1.05rem;
	}

	&__lead {
		margin: 0 0 16px;
		color: var(--color-text-maxcontrast);
		font-size: 0.88rem;
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
		font-size: 0.75rem;
		text-transform: uppercase;
		letter-spacing: 0.05em;
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
	font-size: 0.8rem;

	&--warn {
		background: color-mix(in srgb, var(--color-warning) 22%, transparent);
		color: var(--color-warning-text, var(--color-warning));
	}
}

.muted {
	color: var(--color-text-maxcontrast);
}

.watchlist {
	margin-top: 18px;

	&__title {
		margin: 0 0 8px;
		font-size: 0.95rem;
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
			font-size: 0.9rem;
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
		font-size: 2rem;
		font-weight: 700;
		line-height: 1.1;

		&--warn {
			color: var(--color-warning-text, var(--color-warning));
		}
	}
}
</style>
