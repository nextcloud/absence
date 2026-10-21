<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Add and edit leave types (§3.2), HR only.
-->
<template>
	<NcModal :name="t('absence', 'Leave types')" size="large" @close="$emit('close')">
		<div class="types">
			<div class="types__list">
				<h3>{{ t('absence', 'Leave types') }}</h3>
				<ul>
					<li v-for="type in types" :key="type.id">
						<NcButton
							variant="tertiary"
							wide
							alignment="start"
							:pressed="form && form.id === type.id"
							@click="edit(type)">
							<span aria-hidden="true">{{ type.icon }}</span>
							{{ type.label }}
							<span v-if="!type.enabled" class="types__muted">{{ t('absence', '(disabled)') }}</span>
							<span v-if="type.hrOnly" class="types__muted">{{ t('absence', '(confidential)') }}</span>
						</NcButton>
					</li>
				</ul>
				<NcButton @click="edit(null)">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('absence', 'Add leave type') }}
				</NcButton>
			</div>

			<form v-if="form" class="types__form" @submit.prevent="save">
				<h3>{{ form.id ? t('absence', 'Edit leave type') : t('absence', 'Add leave type') }}</h3>
				<NcTextField
					v-if="!form.id"
					v-model="form.key"
					:label="t('absence', 'Key')"
					:helperText="t('absence', 'A short machine name like training. It cannot be changed later.')"
					maxlength="32"
					required />
				<div class="types__row">
					<NcTextField
						v-model="form.icon"
						class="types__icon"
						:label="t('absence', 'Emoji')"
						maxlength="16" />
					<NcTextField
						v-model="form.label"
						:label="t('absence', 'Name')"
						maxlength="128"
						required />
					<NcColorPicker v-model="form.color" :advancedFields="true">
						<NcButton :aria-label="t('absence', 'Colour')">
							<template #icon>
								<span class="types__swatch" :style="{ background: form.color }" />
							</template>
						</NcButton>
					</NcColorPicker>
				</div>
				<NcCheckboxRadioSwitch
					v-for="flag in flags"
					:key="flag.key"
					v-model="form[flag.key]">
					{{ flag.label }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-if="form.id" v-model="form.enabled" type="switch">
					{{ t('absence', 'Enabled') }}
				</NcCheckboxRadioSwitch>
				<div class="types__buttons">
					<NcButton variant="tertiary" @click="form = null">
						{{ t('absence', 'Cancel') }}
					</NcButton>
					<NcButton type="submit" variant="primary" :disabled="saving">
						{{ t('absence', 'Save') }}
					</NcButton>
				</div>
			</form>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcColorPicker from '@nextcloud/vue/components/NcColorPicker'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Plus from 'vue-material-design-icons/Plus.vue'
import api from '../api.js'
import { store } from '../store.js'

const EDITABLE = ['label', 'color', 'icon', 'countsAgainstBalance', 'requiresApproval', 'requiresNote', 'requiresReplacement', 'employeeRequestable', 'hrOnly']

export default {
	name: 'LeaveTypesDialog',
	components: { NcButton, NcCheckboxRadioSwitch, NcColorPicker, NcModal, NcTextField, Plus },
	emits: ['close'],

	data() {
		return {
			form: null,
			saving: false,
			flags: [
				{ key: 'countsAgainstBalance', label: t('absence', 'Counts against the vacation days') },
				{ key: 'requiresApproval', label: t('absence', 'Needs the manager\'s approval') },
				{ key: 'requiresNote', label: t('absence', 'Needs a note') },
				{ key: 'requiresReplacement', label: t('absence', 'Needs a replacement') },
				{ key: 'employeeRequestable', label: t('absence', 'Employees can request it (otherwise HR records it)') },
				{ key: 'hrOnly', label: t('absence', 'Confidential: only HR sees the type') },
			],
		}
	},

	computed: {
		types() {
			return [...store.leaveTypes].sort((a, b) => a.sortOrder - b.sortOrder)
		},
	},

	methods: {
		t,

		edit(type) {
			this.form = type
				? { ...type }
				: { key: '', label: '', icon: '🌴', color: '#0082c9', countsAgainstBalance: false, requiresApproval: true, requiresNote: false, requiresReplacement: false, employeeRequestable: true, hrOnly: false, sortOrder: this.types.length }
		},

		async save() {
			this.saving = true
			try {
				if (this.form.id) {
					const changes = Object.fromEntries([...EDITABLE, 'enabled'].map((key) => [key, this.form[key]]))
					this.form = { ...await api.updateLeaveType(this.form.id, changes) }
				} else {
					this.form = { ...await api.createLeaveType(this.form) }
				}
				await store.loadLeaveTypes()
				showSuccess(t('absence', 'Leave type saved'))
			} catch (e) {
				showError(e.response?.data?.message || t('absence', 'Could not save the leave type'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.types {
	display: grid;
	grid-template-columns: minmax(200px, 1fr) 2fr;
	gap: 24px;
	padding: 20px;

	@media (max-width: 700px) {
		grid-template-columns: 1fr;
	}

	h3 {
		margin: 0 0 12px;
	}

	ul {
		margin-bottom: 12px;
	}

	&__form {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__row {
		display: flex;
		align-items: flex-end;
		gap: 8px;
	}

	&__icon {
		max-width: 90px;
	}

	&__swatch {
		display: block;
		width: 20px;
		height: 20px;
		border-radius: 50%;
	}

	&__muted {
		color: var(--color-text-maxcontrast);
	}

	&__buttons {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		margin-top: 8px;
	}
}
</style>
