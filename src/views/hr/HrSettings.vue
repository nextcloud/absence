<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page page--narrow page--flat">
		<header class="page__header">
			<h2 class="page__title">
				{{ t('absence', 'Settings') }}
			</h2>
		</header>

		<SkeletonList v-if="loading" :rows="8" />

		<NcEmptyContent
			v-else-if="!form"
			:name="t('absence', 'Could not load the settings')">
			<template #icon>
				<CogOutline :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<NcNoteCard v-if="form.employees_group === ''" type="warning">
				{{ t('absence', 'Absence stays closed until you choose the employees group.') }}
			</NcNoteCard>
			<section class="settings">
				<div class="settings__intro">
					<h3 class="settings__title">
						{{ t('absence', 'Who is who') }}
						<SavedCheck :savedAt="savedAt.who" />
					</h3>
					<p>{{ t('absence', 'Who counts as HR and as an employee.') }}</p>
				</div>
				<div class="settings__fields">
					<div class="settings__field settings__field--wide">
						<NcSelect
							v-if="session.isAdmin"
							:modelValue="hrGroupOption"
							:options="groups"
							:clearable="false"
							:disabled="saving"
							label="displayName"
							:inputLabel="t('absence', 'HR group')"
							@update:modelValue="(group) => save({ hr_group: group.id })" />
						<NcTextField
							v-else
							:modelValue="hrGroupOption.displayName"
							:label="t('absence', 'HR group')"
							:helperText="t('absence', 'Only admins can change this.')"
							disabled>
							<template #icon>
								<Lock :size="18" />
							</template>
						</NcTextField>
					</div>
					<div class="settings__field settings__field--wide">
						<NcSelect
							:modelValue="employeesGroupOption"
							:options="groups"
							:clearable="false"
							:disabled="saving"
							label="displayName"
							:inputLabel="t('absence', 'Employees group')"
							:placeholder="t('absence', 'Choose a group')"
							@update:modelValue="chooseEmployeesGroup">
							<template #selected-option="{ displayName }">
								{{ displayName }}
								<span v-if="employeesCount !== null" class="settings__muted">
									{{ n('absence', '· {count} person', '· {count} people', employeesCount, { count: employeesCount }) }}
								</span>
							</template>
						</NcSelect>
						<p class="settings__hint">
							{{ t('absence', 'Everyone else can\'t request leave and isn\'t listed.') }}
						</p>
					</div>
				</div>
			</section>

			<section class="settings">
				<div class="settings__intro">
					<h3 class="settings__title">
						{{ t('absence', 'Vacation') }}
						<SavedCheck :savedAt="savedAt.vacation" />
					</h3>
					<p>{{ t('absence', 'For everyone HR hasn\'t set days for. Change someone\'s days on their profile or in the Employees list.') }}</p>
				</div>
				<div class="settings__fields">
					<NcTextField
						v-model="form.default_entitlement"
						class="settings__field"
						type="number"
						min="0"
						step="0.5"
						:label="t('absence', 'Default vacation days per year')"
						@change="saveField('default_entitlement')" />
					<div class="settings__field">
						<NcSelect
							:modelValue="carryOverOption"
							:options="carryOverOptions"
							:clearable="false"
							:disabled="saving"
							:inputLabel="t('absence', 'Carry-over')"
							@update:modelValue="(option) => save({ carryover_policy: option.id })" />
					</div>
					<NcTextField
						v-model="form.carryover_cap"
						class="settings__field"
						type="number"
						min="0"
						step="0.5"
						:disabled="form.carryover_policy !== 'capped'"
						:label="t('absence', 'Carry-over limit (days)')"
						@change="saveField('carryover_cap')" />
					<NcTextField
						v-model="form.carryover_expiry"
						class="settings__field"
						placeholder="03-31"
						pattern="(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])"
						:disabled="form.carryover_policy === 'none'"
						:label="t('absence', 'Carried days expire on')"
						:helperText="t('absence', 'MM-DD. Empty keeps them all year.')"
						@change="saveField('carryover_expiry')" />
				</div>
			</section>

			<section class="settings">
				<div class="settings__intro">
					<h3 class="settings__title">
						{{ t('absence', 'Approvals') }}
						<SavedCheck :savedAt="savedAt.approvals" />
					</h3>
					<p>{{ t('absence', 'When a manager doesn\'t answer.') }}</p>
				</div>
				<div class="settings__fields">
					<NcTextField
						v-for="field in approvalFields"
						:key="field.key"
						v-model="form[field.key]"
						class="settings__field"
						type="number"
						min="0"
						step="1"
						:label="field.label"
						:helperText="field.hint"
						@change="saveField(field.key)" />
				</div>
			</section>

			<section class="settings">
				<div class="settings__intro">
					<h3 class="settings__title">
						{{ t('absence', 'Calendar') }}
						<SavedCheck :savedAt="savedAt.calendar" />
					</h3>
				</div>
				<div class="settings__fields settings__fields--stacked">
					<NcCheckboxRadioSwitch
						type="switch"
						:modelValue="form.caldav_personal"
						:disabled="saving"
						@update:modelValue="(on) => save({ caldav_personal: on })">
						{{ t('absence', 'Add approved leave to each person\'s calendar') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						type="switch"
						:modelValue="form.caldav_shared"
						:disabled="saving"
						@update:modelValue="(on) => save({ caldav_shared: on })">
						{{ t('absence', 'Keep a shared team calendar') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						type="switch"
						:modelValue="form.shared_calendar_visibility === 'reveal'"
						:disabled="saving || !form.caldav_shared"
						@update:modelValue="(on) => save({ shared_calendar_visibility: on ? 'reveal' : 'neutral' })">
						{{ t('absence', 'Show the leave type on the shared calendar') }}
						<span class="settings__muted">{{ t('absence', '(otherwise "Absent")') }}</span>
					</NcCheckboxRadioSwitch>
				</div>
			</section>

			<section class="settings">
				<div class="settings__intro">
					<h3 class="settings__title">
						{{ t('absence', 'Public holidays') }}
						<SavedCheck :savedAt="savedAt.holidays" />
					</h3>
					<p>{{ t('absence', 'HR always edits the list. Importing a whole list stays with HR.') }}</p>
				</div>
				<div class="settings__fields settings__fields--stacked">
					<NcCheckboxRadioSwitch
						type="switch"
						:modelValue="form.holidays_everyone_edits"
						:disabled="saving"
						@update:modelValue="(on) => save({ holidays_everyone_edits: on })">
						{{ t('absence', 'Everyone can add and edit public holidays') }}
					</NcCheckboxRadioSwitch>
				</div>
			</section>

			<section class="settings">
				<div class="settings__intro">
					<h3>{{ t('absence', 'Leave types') }}</h3>
				</div>
				<div v-if="session.isHr" class="settings__fields settings__fields--chips">
					<LeaveTypeChip
						v-for="type in enabledTypes"
						:key="type.id"
						:typeId="type.id" />
					<NcButton @click="managingTypes = true">
						{{ t('absence', 'Manage leave types') }}
					</NcButton>
				</div>
				<p v-else class="settings__hint">
					{{ t('absence', 'Leave types are managed by HR, as some of them are confidential.') }}
				</p>
			</section>
		</template>

		<EmployeesGroupDialog
			v-if="pendingEmployeesGroup"
			:group="pendingEmployeesGroup"
			:saving="saving"
			@close="pendingEmployeesGroup = null"
			@confirm="changeEmployeesGroup" />

		<LeaveTypesDialog v-if="managingTypes" @close="managingTypes = false" />
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import EmployeesGroupDialog from '../../components/EmployeesGroupDialog.vue'
import LeaveTypeChip from '../../components/LeaveTypeChip.vue'
import LeaveTypesDialog from '../../components/LeaveTypesDialog.vue'
import SavedCheck from '../../components/SavedCheck.vue'
import SkeletonList from '../../components/SkeletonList.vue'
import api from '../../api.js'
import { store } from '../../store.js'

// The section each setting is shown in, to flash its check once saved
const SECTION_OF = {
	hr_group: 'who',
	employees_group: 'who',
	default_entitlement: 'vacation',
	carryover_policy: 'vacation',
	carryover_cap: 'vacation',
	carryover_expiry: 'vacation',
	escalation_window_days: 'approvals',
	reminder_lead_days: 'approvals',
	max_concurrent_absences: 'approvals',
	notice_period_days: 'approvals',
	caldav_personal: 'calendar',
	caldav_shared: 'calendar',
	shared_calendar_visibility: 'calendar',
	holidays_everyone_edits: 'holidays',
}

export default {
	name: 'HrSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcEmptyContent, NcNoteCard, NcSelect, NcTextField, CogOutline, Lock, EmployeesGroupDialog, LeaveTypeChip, LeaveTypesDialog, SavedCheck, SkeletonList },
	data() {
		return {
			loading: true,
			saving: false,
			// Server values, and the editable copy the fields bind to
			values: null,
			form: null,
			groups: [],
			employeesCount: null,
			pendingEmployeesGroup: null,
			managingTypes: false,
			// Section → when it was last saved
			savedAt: {},
			carryOverOptions: [
				{ id: 'none', label: t('absence', 'No carry-over') },
				{ id: 'capped', label: t('absence', 'Up to a limit') },
				{ id: 'unlimited', label: t('absence', 'Unlimited') },
			],

			approvalFields: [
				{ key: 'escalation_window_days', label: t('absence', 'Escalate to HR after (working days)') },
				{ key: 'reminder_lead_days', label: t('absence', 'Remind the manager (days before)') },
				{ key: 'max_concurrent_absences', label: t('absence', 'Too many people off at once (per team)') },
				{ key: 'notice_period_days', label: t('absence', 'Short notice under (days)'), hint: t('absence', '0 switches the warning off.') },
			],
		}
	},

	computed: {
		session() {
			return store.session
		},

		hrGroupOption() {
			return this.groupOption(this.form.hr_group)
		},

		employeesGroupOption() {
			return this.form.employees_group === '' ? null : this.groupOption(this.form.employees_group)
		},

		carryOverOption() {
			return this.carryOverOptions.find((option) => option.id === this.form.carryover_policy)
		},

		enabledTypes() {
			return [...store.enabledLeaveTypes].sort((a, b) => a.sortOrder - b.sortOrder)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		async load() {
			this.loading = true
			try {
				this.apply(await api.getSettings())
			} catch (e) {
				showError(e.response?.data?.message || t('absence', 'Could not load the settings'))
			} finally {
				this.loading = false
			}
		},

		apply({ values, groups, employeesCount }) {
			this.values = values
			this.form = { ...values }
			this.groups = groups
			this.employeesCount = employeesCount
		},

		/**
		 * A group as a select option, also when it no longer exists.
		 *
		 * @param {string} id group id
		 */
		groupOption(id) {
			return this.groups.find((group) => group.id === id) || { id, displayName: id }
		},

		saveField(key) {
			if (String(this.form[key]) === String(this.values[key])) {
				return
			}
			this.save({ [key]: this.form[key] })
		},

		async save(patch) {
			this.saving = true
			try {
				this.apply(await api.updateSettings(patch))
				for (const key of Object.keys(patch)) {
					this.savedAt[SECTION_OF[key]] = Date.now()
				}
				if (['hr_group', 'employees_group', 'holidays_everyone_edits'].some((key) => key in patch)) {
					// The session carries whether the app is open, this user still is HR, and may edit holidays.
					await store.refreshSession()
				}
				return true
			} catch (e) {
				showError(e.response?.data?.message || t('absence', 'Could not save the setting'))
				this.form = { ...this.values }
				return false
			} finally {
				this.saving = false
			}
		},

		/**
		 * The first choice opens the app, nothing to confirm; a change says who leaves and joins.
		 *
		 * @param {{id: string, displayName: string}} group the chosen group
		 */
		chooseEmployeesGroup(group) {
			if (group.id === this.form.employees_group) {
				return
			}
			if (this.form.employees_group === '') {
				this.save({ employees_group: group.id })
			} else {
				this.pendingEmployeesGroup = group
			}
		},

		async changeEmployeesGroup() {
			if (await this.save({ employees_group: this.pendingEmployeesGroup.id })) {
				this.pendingEmployeesGroup = null
			}
		},
	},
}
</script>

<style scoped lang="scss">
.settings {
	display: grid;
	grid-template-columns: minmax(180px, 1fr) 2.5fr;
	gap: 16px 24px;
	padding-block: 20px;
	border-top: 1px solid var(--color-border);

	&:first-of-type {
		border-top: none;
		padding-top: 0;
	}

	@media (max-width: 800px) {
		grid-template-columns: 1fr;
	}

	h3 {
		margin: 0;
		font-size: 1.1rem;
	}

	&__title {
		display: flex;
		align-items: center;
		gap: 6px;
	}

	&__intro p {
		margin: 4px 0 0;
		color: var(--color-text-maxcontrast);
	}

	&__fields {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
		gap: 16px;
		align-items: start;

		&--stacked {
			display: flex;
			flex-direction: column;
			gap: 4px;
		}

		&--chips {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 8px;
		}
	}

	&__field--wide {
		grid-column: span 2;

		@media (max-width: 800px) {
			grid-column: auto;
		}
	}

	&__hint {
		margin: 4px 0 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__muted {
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}
}
</style>
