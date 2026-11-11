<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Confirms a change of the employees group: who leaves the list, who joins (§12).
-->
<template>
	<NcDialog
		:name="title"
		:open="true"
		size="normal"
		@update:open="(open) => !open && $emit('close')">
		<div class="change">
			<NcNoteCard type="warning">
				{{ t('absence', 'This changes who can request leave. Nobody is told.') }}
			</NcNoteCard>

			<SkeletonList v-if="!preview" :rows="2" />
			<template v-else>
				<div>
					<p>
						<strong>{{ n('absence', '{count} person leaves the list', '{count} people leave the list', preview.leaving.count, { count: preview.leaving.count }) }}</strong><span v-if="preview.leaving.count">: {{ namesAndMore(preview.leaving) }}</span>
					</p>
					<p v-if="preview.leaving.count" class="change__hint">
						{{ t('absence', 'They can\'t request leave any more and drop out of balances, reports and timelines. Their data is kept.') }}
					</p>
				</div>
				<div>
					<p>
						<strong>{{ n('absence', '{count} person joins', '{count} people join', preview.joining.count, { count: preview.joining.count }) }}</strong><span v-if="preview.joining.count">: {{ namesAndMore(preview.joining) }}</span>
					</p>
					<p v-if="preview.joining.count" class="change__hint">
						{{ t('absence', 'They start with the default vacation days.') }}
					</p>
				</div>
			</template>

			<NcTextField
				:modelValue="typed"
				:label="t('absence', 'Type the group name to confirm')"
				:placeholder="group.displayName"
				@update:modelValue="typed = String($event)"
				@keydown.enter="confirm" />
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('absence', 'Cancel') }}
			</NcButton>
			<NcButton variant="error" :disabled="!confirmed" @click="confirm">
				{{ t('absence', 'Change group') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import type { EmployeesPreview, Group } from '../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SkeletonList from './SkeletonList.vue'
import api from '../services/api.ts'
import { serverMessage } from '../utils/errors.ts'
import { namesAndMore } from '../utils/people.ts'

const props = withDefaults(defineProps<{
	/** The group to switch to: { id, displayName } */
	group: Group
	saving?: boolean
}>(), {
	saving: false,
})

const emit = defineEmits<{
	close: []
	confirm: []
}>()

const preview = ref<EmployeesPreview | null>(null)
const typed = ref('')

const title = computed(() => t('absence', 'Change the employees group to "{group}"?', { group: props.group.displayName }, undefined, { escape: false }))

const confirmed = computed(() => preview.value !== null && !props.saving && typed.value.trim() === props.group.displayName)

onMounted(async () => {
	try {
		preview.value = await api.previewEmployeesGroup(props.group.id)
	} catch (e) {
		showError(serverMessage(e) || t('absence', 'Could not check who this group changes'))
		emit('close')
	}
})

/**
 * Confirms the change once the group name is typed.
 */
function confirm() {
	if (confirmed.value) {
		emit('confirm')
	}
}
</script>

<style scoped lang="scss">
.change {
	display: flex;
	flex-direction: column;
	gap: 12px;

	p {
		margin: 0;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
	}
}
</style>
