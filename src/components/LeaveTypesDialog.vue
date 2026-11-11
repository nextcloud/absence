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
							:pressed="form ? form.id === type.id : undefined"
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
					:modelValue="form.key"
					:label="t('absence', 'Key')"
					:helperText="t('absence', 'A short machine name like training. It cannot be changed later.')"
					maxlength="32"
					required
					@update:modelValue="form.key = String($event)" />
				<div class="types__row">
					<NcTextField
						:modelValue="form.icon"
						class="types__icon"
						:label="t('absence', 'Emoji')"
						maxlength="16"
						@update:modelValue="form.icon = String($event)" />
					<NcTextField
						:modelValue="form.label"
						:label="t('absence', 'Name')"
						maxlength="128"
						required
						@update:modelValue="form.label = String($event)" />
					<NcColorPicker :modelValue="form.color" :advancedFields="true" @update:modelValue="form.color = $event ?? form.color">
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

<script setup lang="ts">
import type { LeaveType } from '../types/index.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcColorPicker from '@nextcloud/vue/components/NcColorPicker'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Plus from 'vue-material-design-icons/Plus.vue'
import api from '../services/api.ts'
import { useLeaveTypesStore } from '../stores/leaveTypes.ts'
import { serverMessage } from '../utils/errors.ts'

defineEmits<{
	close: []
}>()

const leaveTypesStore = useLeaveTypesStore()

/** A leave type while it is edited: a new one has no id yet, and is enabled by the server */
interface LeaveTypeForm extends Omit<LeaveType, 'id' | 'enabled'> {
	id?: number
	enabled?: boolean
}

/** A yes or no setting of a leave type, with the label of its checkbox */
interface Flag {
	key: 'countsAgainstBalance' | 'requiresApproval' | 'requiresNote' | 'requiresReplacement' | 'employeeRequestable' | 'hrOnly'
	label: string
}

const form = ref<LeaveTypeForm | null>(null)
const saving = ref(false)
const flags: Flag[] = [
	{ key: 'countsAgainstBalance', label: t('absence', 'Counts against the vacation days') },
	{ key: 'requiresApproval', label: t('absence', 'Needs the manager\'s approval') },
	{ key: 'requiresNote', label: t('absence', 'Needs a note') },
	{ key: 'requiresReplacement', label: t('absence', 'Needs a replacement') },
	{ key: 'employeeRequestable', label: t('absence', 'Employees can request it (otherwise HR records it)') },
	{ key: 'hrOnly', label: t('absence', 'Confidential: only HR sees the type') },
]

const types = computed(() => [...leaveTypesStore.leaveTypes].sort((a, b) => a.sortOrder - b.sortOrder))

/**
 * @param type the leave type to edit, null to add one
 */
function edit(type: LeaveType | null) {
	form.value = type
		? { ...type }
		: { key: '', label: '', icon: '🌴', color: '#0082c9', countsAgainstBalance: false, requiresApproval: true, requiresNote: false, requiresReplacement: false, employeeRequestable: true, hrOnly: false, sortOrder: types.value.length }
}

/**
 * Saves the edited type, or creates the new one, and reloads the list everyone uses.
 */
async function save() {
	const edited = form.value
	if (!edited) {
		return
	}
	saving.value = true
	try {
		if (edited.id) {
			const { label, color, icon, countsAgainstBalance, requiresApproval, requiresNote, requiresReplacement, employeeRequestable, hrOnly, enabled } = edited
			form.value = { ...await api.updateLeaveType(edited.id, { label, color, icon, countsAgainstBalance, requiresApproval, requiresNote, requiresReplacement, employeeRequestable, hrOnly, enabled }) }
		} else {
			form.value = { ...await api.createLeaveType(edited) }
		}
		await leaveTypesStore.loadLeaveTypes()
		showSuccess(t('absence', 'Leave type saved'))
	} catch (e) {
		showError(serverMessage(e) || t('absence', 'Could not save the leave type'))
	} finally {
		saving.value = false
	}
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
