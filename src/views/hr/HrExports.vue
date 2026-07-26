<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page">
		<header class="page__header">
			<h2 class="page__title">
				{{ t('absence', 'Exports') }}
			</h2>
		</header>

		<div class="cards">
			<div class="card">
				<h3>{{ t('absence', 'Requests') }}</h3>
				<p>{{ t('absence', 'All leave requests overlapping the selected date range, as CSV.') }}</p>
				<div class="card__row">
					<NcDateTimePickerNative v-model="from" type="date" :label="t('absence', 'From')" />
					<NcDateTimePickerNative v-model="to" type="date" :label="t('absence', 'To')" />
				</div>
				<a :href="requestsUrl" class="dl">
					<NcButton variant="primary">
						<template #icon><Download :size="20" /></template>
						{{ t('absence', 'Download requests CSV') }}
					</NcButton>
				</a>
			</div>

			<div class="card">
				<h3>{{ t('absence', 'Balances') }}</h3>
				<p>{{ t('absence', 'Per-employee entitlement, used, remaining and carry-over for a year.') }}</p>
				<div class="card__row">
					<NcSelect
						v-model="year"
						:options="years"
						:clearable="false"
						:aria-label-combobox="t('absence', 'Year')" />
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
		return {
			from: new Date(now.getFullYear(), 0, 1),
			to: new Date(now.getFullYear(), 11, 31),
			year: now.getFullYear(),
			years: [now.getFullYear() - 1, now.getFullYear(), now.getFullYear() + 1],
		}
	},

	computed: {
		requestsUrl() {
			return api.exportRequestsUrl(toIso(this.from), toIso(this.to))
		},

		balancesUrl() {
			return api.exportBalancesUrl(this.year)
		},
	},

	methods: { t },
}
</script>

<style scoped lang="scss">
.page {
	max-width: 900px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline, 4px) * 5);

	&__title {
		margin: 0 0 24px;
		font-size: 1.6rem;
	}
}

.cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: 16px;
}

.card {
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large, 12px);
	padding: calc(var(--default-grid-baseline, 4px) * 4);
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
}

.dl {
	text-decoration: none;
}
</style>
