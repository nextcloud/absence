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
				v-model="typed"
				:label="t('absence', 'Type the group name to confirm')"
				:placeholder="group.displayName"
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

<script>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SkeletonList from './SkeletonList.vue'
import api from '../api.js'
import { namesAndMore } from '../utils/people.js'

export default {
	name: 'EmployeesGroupDialog',
	components: { NcButton, NcDialog, NcNoteCard, NcTextField, SkeletonList },
	props: {
		/** The group to switch to: { id, displayName } */
		group: { type: Object, required: true },
		saving: { type: Boolean, default: false },
	},

	emits: ['close', 'confirm'],

	data() {
		return {
			preview: null,
			typed: '',
		}
	},

	computed: {
		title() {
			return t('absence', 'Change the employees group to "{group}"?', { group: this.group.displayName }, undefined, { escape: false })
		},

		confirmed() {
			return this.preview !== null && !this.saving && this.typed.trim() === this.group.displayName
		},
	},

	async mounted() {
		try {
			this.preview = await api.previewEmployeesGroup(this.group.id)
		} catch (e) {
			showError(e.response?.data?.message || t('absence', 'Could not check who this group changes'))
			this.$emit('close')
		}
	},

	methods: {
		t,
		n,
		namesAndMore,

		confirm() {
			if (this.confirmed) {
				this.$emit('confirm')
			}
		},
	},
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
