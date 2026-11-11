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
				<NcButton
					class="dl"
					variant="primary"
					:href="requestsUrl ?? undefined"
					:disabled="!requestsUrl">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('absence', 'Download requests CSV') }}
				</NcButton>
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
				<NcButton class="dl" variant="primary" :href="balancesUrl">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('absence', 'Download balances CSV') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { Group } from '../../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { computed, onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import Download from 'vue-material-design-icons/Download.vue'
import api from '../../services/api.ts'
import { toIso } from '../../utils/dates.ts'

const now = new Date()
const everyone = reactive<Group>({ id: '', displayName: t('absence', 'All employees') })

const from = ref<Date | null>(new Date(now.getFullYear(), 0, 1))
const to = ref<Date | null>(new Date(now.getFullYear(), 11, 31))
const year = ref(now.getFullYear())
const years = [now.getFullYear() - 1, now.getFullYear(), now.getFullYear() + 1]
const groups = ref<Group[]>([])
const requestsGroup = ref<Group | null>(everyone)
const balancesGroup = ref<Group | null>(everyone)

const groupOptions = computed<Group[]>(() => [everyone, ...groups.value])

/** Null while a date is cleared: there is no range to export then. */
const requestsUrl = computed<string | null>(() => {
	if (!from.value || !to.value) {
		return null
	}
	return api.exportRequestsUrl(toIso(from.value), toIso(to.value), requestsGroup.value?.id || '')
})

const balancesUrl = computed<string>(() => api.exportBalancesUrl(year.value, balancesGroup.value?.id || ''))

onMounted(async () => {
	try {
		groups.value = await api.listGroups()
	} catch {
		// The filter stays at "All employees": the export works without it.
		showError(t('absence', 'Could not load the groups to filter by'))
	}
})
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
	align-self: flex-start;
}
</style>
