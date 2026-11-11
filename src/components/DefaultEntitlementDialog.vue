<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - After the default entitlement changed: asks whether the people who had the
  - old default this year get the new one too (§6.1).
-->
<template>
	<NcDialog
		:name="t('absence', 'Give the new default to those on the old one?')"
		:open="true"
		size="normal"
		@update:open="(open) => !open && $emit('close')">
		<div class="handon">
			<div class="handon__figures">
				<span class="handon__days handon__days--old">{{ change.from }}</span>
				<ArrowRight :size="20" />
				<span class="handon__days">{{ change.to }}</span>
				<span class="handon__unit">{{ t('absence', 'days per year') }}</span>
			</div>
			<p>
				<strong>{{ n('absence', '{count} person has {days} days for {year}.', '{count} people have {days} days for {year}.', change.count, { count: change.count, days: change.from, year: change.year }) }}</strong>
			</p>
			<p class="handon__hint">
				{{ t('absence', 'The new default is saved. It reaches people without days of their own, such as new joiners. People who already have days for this year keep them unless you change them here.') }}
			</p>
			<p class="handon__hint">
				{{ t('absence', 'Anyone with a different number of days keeps it. Past years do not change. Each change goes to the person\'s entitlement history.') }}
			</p>
		</div>

		<template #actions>
			<NcButton :disabled="applying" @click="$emit('close')">
				{{ t('absence', 'Keep their days') }}
			</NcButton>
			<NcButton variant="primary" :disabled="applying" @click="apply">
				{{ n('absence', 'Give {days} days to {count} person', 'Give {days} days to {count} people', change.count, { count: change.count, days: change.to }) }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import type { DefaultEntitlementChange } from '../types/index.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import api from '../services/api.ts'
import { serverMessage } from '../utils/errors.ts'

const props = defineProps<{
	change: DefaultEntitlementChange
}>()

const emit = defineEmits<{
	close: []
}>()

const applying = ref(false)

/**
 * Hands the new default on, then closes.
 */
async function apply(): Promise<void> {
	applying.value = true
	try {
		const { affected } = await api.applyDefaultEntitlement(props.change.from, props.change.to)
		showSuccess(n('absence', '{count} person now has {days} days', '{count} people now have {days} days', affected, { count: affected, days: props.change.to }))
		emit('close')
	} catch (e) {
		showError(serverMessage(e) || t('absence', 'Could not change their days'))
	} finally {
		applying.value = false
	}
}
</script>

<style scoped lang="scss">
.handon {
	display: flex;
	flex-direction: column;
	gap: 12px;

	p {
		margin: 0;
	}

	&__figures {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 12px 16px;
		border-radius: var(--border-radius-large);
		background: var(--color-background-hover);
	}

	&__days {
		font-size: 1.6rem;
		font-weight: bold;

		&--old {
			color: var(--color-text-maxcontrast);
			text-decoration: line-through;
		}
	}

	&__unit,
	&__hint {
		color: var(--color-text-maxcontrast);
	}
}
</style>
