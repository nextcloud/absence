<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection :name="t('absence', 'Absence')"
		:description="t('absence', 'Configure the vacation approval workflow.')">
		<div class="grid">
			<div class="field">
				<label>{{ t('absence', 'HR group') }}</label>
				<NcTextField v-model="config.hr_group" :placeholder="'hr'" />
			</div>
			<div class="field">
				<label>{{ t('absence', 'Default annual entitlement (days)') }}</label>
				<NcTextField v-model="config.default_entitlement" type="number" />
			</div>
			<div class="field">
				<label>{{ t('absence', 'Escalation window (working days)') }}</label>
				<NcTextField v-model="config.escalation_window_days" type="number" />
			</div>
			<div class="field">
				<label>{{ t('absence', 'Reminder lead time (days)') }}</label>
				<NcTextField v-model="config.reminder_lead_days" type="number" />
			</div>
			<div class="field">
				<label>{{ t('absence', 'Carry-over policy') }}</label>
				<NcSelect v-model="carryPolicy" :options="carryOptions" label="label" :clearable="false" />
			</div>
			<div class="field">
				<label>{{ t('absence', 'Carry-over cap (days)') }}</label>
				<NcTextField v-model="config.carryover_cap" type="number" />
			</div>
			<div class="field">
				<label>{{ t('absence', 'Carry-over expiry (MM-DD, optional)') }}</label>
				<NcTextField v-model="config.carryover_expiry" placeholder="03-31" />
			</div>
			<div class="field">
				<label>{{ t('absence', 'Max concurrent team absences') }}</label>
				<NcTextField v-model="config.max_concurrent_absences" type="number" />
			</div>
		</div>

		<div class="switches">
			<NcCheckboxRadioSwitch v-model="config.caldav_personal" type="switch">
				{{ t('absence', 'Write approved leave to each user\'s personal calendar') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="config.caldav_shared" type="switch">
				{{ t('absence', 'Write approved leave to a shared team calendar') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch :model-value="config.shared_calendar_visibility === 'reveal'"
				type="switch"
				@update:model-value="(v) => config.shared_calendar_visibility = v ? 'reveal' : 'neutral'">
				{{ t('absence', 'Reveal leave type on the shared calendar (otherwise shows “Absent”)') }}
			</NcCheckboxRadioSwitch>
		</div>

		<NcButton type="primary" :disabled="saving" @click="save">
			{{ t('absence', 'Save settings') }}
		</NcButton>
	</NcSettingsSection>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { loadState } from '@nextcloud/initial-state'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import api from '../../api.js'

export default {
	name: 'AdminSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcSelect, NcSettingsSection, NcTextField },
	data() {
		return {
			config: loadState('absence', 'adminConfig'),
			saving: false,
			carryOptions: [
				{ id: 'none', label: t('absence', 'No carry-over') },
				{ id: 'capped', label: t('absence', 'Capped') },
				{ id: 'unlimited', label: t('absence', 'Unlimited') },
			],
		}
	},
	computed: {
		carryPolicy: {
			get() {
				return this.carryOptions.find((o) => o.id === this.config.carryover_policy) || this.carryOptions[1]
			},
			set(v) {
				this.config.carryover_policy = v.id
			},
		},
	},
	methods: {
		t,
		async save() {
			this.saving = true
			try {
				await api.updateAdminConfig(this.config)
				showSuccess(t('absence', 'Settings saved'))
			} catch (e) {
				showError(t('absence', 'Could not save settings'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 16px;
	margin-bottom: 20px;
}

.field {
	display: flex;
	flex-direction: column;
	gap: 4px;

	label {
		font-weight: 600;
		font-size: 0.85rem;
	}
}

.switches {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 20px;
}
</style>
