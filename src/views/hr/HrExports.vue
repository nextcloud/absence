<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page page--narrow page--flat">
		<header class="page__header">
			<h2 class="page__title">
				{{ t('absence', 'Exports') }}
			</h2>
		</header>

		<div class="tiles tiles--wide">
			<div class="surface card">
				<h3><span aria-hidden="true">🗂️</span> {{ t('absence', 'Requests') }}</h3>
				<p>{{ t('absence', 'All leave requests overlapping the selected date range, as CSV.') }}</p>
				<div class="card__row">
					<NcDateTimePickerNative v-model="from" type="date" :label="t('absence', 'From')" />
					<NcDateTimePickerNative v-model="to" type="date" :label="t('absence', 'To')" />
				</div>
				<div class="card__row">
					<NcSelect
						v-model="requestsGroup"
						class="card__group"
						:options="groupOptions"
						:clearable="false"
						label="displayName"
						:aria-label-combobox="t('absence', 'Group')" />
				</div>
				<a :href="requestsUrl" class="dl">
					<NcButton variant="primary">
						<template #icon><Download :size="20" /></template>
						{{ t('absence', 'Download requests CSV') }}
					</NcButton>
				</a>
			</div>

			<div class="surface card">
				<h3><span aria-hidden="true">⚖️</span> {{ t('absence', 'Balances') }}</h3>
				<p>{{ t('absence', 'Per-employee entitlement, used, remaining and carry-over for a year.') }}</p>
				<div class="card__row">
					<NcSelect
						v-model="year"
						:options="years"
						:clearable="false"
						:aria-label-combobox="t('absence', 'Year')" />
					<NcSelect
						v-model="balancesGroup"
						class="card__group"
						:options="groupOptions"
						:clearable="false"
						label="displayName"
						:aria-label-combobox="t('absence', 'Group')" />
				</div>
				<a :href="balancesUrl" class="dl">
					<NcButton variant="primary">
						<template #icon><Download :size="20" /></template>
						{{ t('absence', 'Download balances CSV') }}
					</NcButton>
				</a>
			</div>
		</div>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import Download from 'vue-material-design-icons/Download.vue'
import api from '../../api.js'
import { toIso } from '../../utils/dates.js'

export default {
	name: 'HrExports',
	components: { NcButton, NcDateTimePickerNative, NcSelect, Download },
	data() {
		const now = new Date()
		const everyone = { id: '', displayName: t('absence', 'All employees') }
		return {
			from: new Date(now.getFullYear(), 0, 1),
			to: new Date(now.getFullYear(), 11, 31),
			year: now.getFullYear(),
			years: [now.getFullYear() - 1, now.getFullYear(), now.getFullYear() + 1],
			everyone,
			groups: [],
			requestsGroup: everyone,
			balancesGroup: everyone,
		}
	},

	computed: {
		groupOptions() {
			return [this.everyone, ...this.groups]
		},

		requestsUrl() {
			return api.exportRequestsUrl(toIso(this.from), toIso(this.to), this.requestsGroup?.id || '')
		},

		balancesUrl() {
			return api.exportBalancesUrl(this.year, this.balancesGroup?.id || '')
		},
	},

	async mounted() {
		try {
			this.groups = await api.listGroups()
		} catch {
			// The filter simply stays at "All employees" — the export works without it.
		}
	},

	methods: { t },
}
</script>

<style scoped lang="scss">

// Export cards hold a form, so they need more width than a stat tile.
.tiles--wide {
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
}

.card {
	display: flex;
	flex-direction: column;
	gap: 12px;

	h3 { margin: 0; }
	p { margin: 0; color: var(--color-text-maxcontrast); }

	&__row {
		display: flex;
		gap: 12px;
		flex-wrap: wrap;
	}

	&__group {
		min-width: 200px;
	}
}

.dl {
	text-decoration: none;
}
</style>
