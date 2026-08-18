<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page">
		<header class="page__header">
			<h2 class="page__title">
				{{ t('absence', 'Team') }}
			</h2>
		</header>
		<TeamTimeline scope="team" />

		<!-- The number a manager needs while deciding: how many days each report
		     has left. Same permission as opening each report's request detail
		     (the server scopes the endpoint to the caller's own reports). -->
		<section v-if="balances.length" class="team-balances">
			<h3 class="team-balances__title">
				{{ t('absence', 'Balances {year}', { year }) }}
			</h3>
			<div class="table-wrap">
				<table class="tbl">
					<thead>
						<tr>
							<th>{{ t('absence', 'Employee') }}</th>
							<th>{{ t('absence', 'Type') }}</th>
							<th class="num">
								{{ t('absence', 'Entitlement') }}
							</th>
							<th class="num">
								{{ t('absence', 'Used') }}
							</th>
							<th class="num">
								{{ t('absence', 'Pending') }}
							</th>
							<th class="num">
								{{ t('absence', 'Available') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in balances" :key="row.employeeUid + '-' + row.typeId">
							<td>
								<div class="emp">
									<NcAvatar
										:user="row.employeeUid"
										:displayName="row.displayName"
										:size="24"
										hideStatus /> {{ row.displayName }}
								</div>
							</td>
							<td><span class="type"><span aria-hidden="true">{{ row.typeIcon }}</span> {{ row.typeLabel }}</span></td>
							<td class="num">
								{{ fmt(row.entitlement) }}
							</td>
							<td class="num">
								{{ fmt(row.used) }}
							</td>
							<td class="num">
								{{ fmt(row.pending) }}
							</td>
							<td class="num lead" :class="availableClass(row)">
								{{ fmt(row.available) }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import TeamTimeline from '../components/TeamTimeline.vue'
import api from '../api.js'

export default {
	name: 'Team',
	components: { NcAvatar, TeamTimeline },

	data() {
		return {
			year: new Date().getFullYear(),
			balances: [],
		}
	},

	async mounted() {
		try {
			this.balances = await api.getTeamBalances(this.year)
		} catch {
			// The timeline stands on its own; a failed balances call costs the
			// table, not the page.
		}
	},

	methods: {
		t,
		fmt(v) {
			return v === null || v === undefined ? '—' : Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 })
		},

		availableClass(row) {
			return (row.available ?? 0) < 0 ? 'neg' : ''
		},
	},
}
</script>

<style scoped lang="scss">
.team-balances {
	margin-top: 24px;

	&__title {
		margin: 0 0 8px;
	}
}

.emp, .type {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}

.num.lead {
	font-weight: 600;

	&.neg {
		color: var(--color-error-text);
	}
}
</style>
