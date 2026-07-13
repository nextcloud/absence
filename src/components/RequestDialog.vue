<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Create / edit a leave request with a live working-day + balance preview (§15.2).
-->
<template>
	<NcModal :name="dialogTitle"
		size="normal"
		@close="$emit('close')">
		<div class="dialog">
			<h2 class="dialog__title">
				{{ dialogTitle }}
			</h2>

			<NcNoteCard v-if="hrMode" type="info">
				{{ t('absence', 'Record an absence on behalf of an employee (e.g. sick leave). It is booked directly, without an approval step.') }}
			</NcNoteCard>

			<div v-if="hrMode" class="dialog__field">
				<label class="dialog__label">{{ t('absence', 'Employee') }}</label>
				<NcSelect v-model="selectedEmployee"
					:options="employeeOptions"
					:loading="employeeLoading"
					:user-select="true"
					label="displayName"
					:filterable="false"
					:placeholder="t('absence', 'Search for an employee…')"
					:aria-label-combobox="t('absence', 'Employee')"
					@search="onEmployeeSearch" />
			</div>

			<div class="dialog__field">
				<label class="dialog__label">{{ t('absence', 'Leave type') }}</label>
				<NcSelect v-model="selectedType"
					:options="typeOptions"
					label="label"
					:clearable="false"
					:aria-label-combobox="t('absence', 'Leave type')">
					<template #option="{ icon, label }">
						<span class="opt"><span class="opt__icon">{{ icon }}</span>{{ label }}</span>
					</template>
					<template #selected-option="{ icon, label }">
						<span class="opt"><span class="opt__icon">{{ icon }}</span>{{ label }}</span>
					</template>
				</NcSelect>
			</div>

			<div v-if="needsReplacement" class="dialog__field">
				<label class="dialog__label">
					{{ t('absence', 'Replacement') }}<span class="dialog__req">*</span>
				</label>
				<NcSelect v-model="selectedReplacement"
					:options="replacementOptions"
					:loading="replacementLoading"
					:user-select="true"
					label="displayName"
					:filterable="false"
					:placeholder="t('absence', 'Who covers for you?')"
					:aria-label-combobox="t('absence', 'Replacement')"
					@search="onReplacementSearch" />
				<p class="dialog__hint">{{ t('absence', 'A colleague who covers your duties while you are away. They are notified once your leave is approved.') }}</p>
			</div>

			<div class="dialog__row">
				<div class="dialog__field">
					<label class="dialog__label">{{ t('absence', 'From') }}</label>
					<NcDateTimePickerNative v-model="startDate" type="date" />
				</div>
				<div class="dialog__field">
					<label class="dialog__label">{{ t('absence', 'To') }}</label>
					<NcDateTimePickerNative v-model="endDate" type="date" />
				</div>
			</div>

			<div class="dialog__field">
				<label class="dialog__label">
					{{ t('absence', 'Working days') }}<span class="dialog__req">*</span>
				</label>
				<NcTextField v-model="workingDays"
					type="number"
					min="0"
					step="0.5"
					:label="t('absence', 'Working days')"
					:label-visible="false" />
				<p class="dialog__hint">{{ t('absence', 'Number of working days this absence covers (excluding weekends and public holidays). Your manager will verify it.') }}</p>
			</div>

			<div v-if="balanceRow && !hrMode && workingDaysNum > 0" class="preview" :style="{ '--type-color': typeColor }">
				<div class="preview__balance">
					<span>{{ t('absence', 'Available') }}</span>
					<strong>{{ formatDays(balanceRow.available) }} → {{ formatDays(projectedAvailable) }}</strong>
					<span class="preview__bar" role="presentation">
						<span class="preview__bar-fill" :style="{ width: availablePct + '%' }" />
					</span>
				</div>
			</div>

			<NcNoteCard v-if="wouldGoNegative" type="warning">
				{{ t('absence', 'Heads up: this goes beyond your available balance. You can still submit — HR may approve it.') }}
			</NcNoteCard>

			<div class="dialog__field">
				<label class="dialog__label">
					{{ t('absence', 'Reason') }}
					<span v-if="requiresNote" class="dialog__req">*</span>
					<span v-else class="dialog__optional">{{ t('absence', '(optional)') }}</span>
				</label>
				<NcTextArea v-model="reason"
					:placeholder="t('absence', 'Optional note for your manager')"
					resize="vertical"
					rows="2" />
			</div>

			<div class="dialog__actions">
				<NcButton type="tertiary" @click="$emit('close')">
					{{ t('absence', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="!canSubmit || submitting" @click="submit">
					<template #icon>
						<NcLoadingIcon v-if="submitting" :size="20" />
						<Send v-else :size="20" />
					</template>
					{{ submitLabel }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Send from 'vue-material-design-icons/Send.vue'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { store } from '../store.js'
import { toIso } from '../utils/dates.js'
import api from '../api.js'

export default {
	name: 'RequestDialog',
	components: {
		NcModal,
		NcSelect,
		NcDateTimePickerNative,
		NcTextArea,
		NcTextField,
		NcNoteCard,
		NcButton,
		NcLoadingIcon,
		Send,
	},
	props: {
		request: { type: Object, default: null },
		// HR "record absence for an employee" mode (e.g. sick leave).
		hrMode: { type: Boolean, default: false },
	},
	emits: ['close', 'saved'],
	data() {
		return {
			selectedType: null,
			startIso: null,
			endIso: null,
			workingDays: '',
			reason: '',
			submitting: false,
			selectedEmployee: null,
			employeeOptions: [],
			employeeLoading: false,
			selectedReplacement: null,
			replacementOptions: [],
			replacementLoading: false,
		}
	},
	computed: {
		isEdit() {
			return this.request !== null
		},
		dialogTitle() {
			if (this.hrMode) {
				return t('absence', 'Record absence')
			}
			return this.isEdit ? t('absence', 'Edit request') : t('absence', 'Request time off')
		},
		submitLabel() {
			if (this.isEdit) {
				return t('absence', 'Save changes')
			}
			// HR records directly — there is no request/approval flow.
			return this.hrMode ? t('absence', 'Record') : t('absence', 'Submit request')
		},
		typeOptions() {
			// HR may record any enabled type (incl. sick); employees only self-requestable ones.
			return this.hrMode ? store.enabledLeaveTypes : store.requestableLeaveTypes
		},
		typeColor() {
			return this.selectedType ? this.selectedType.color : 'var(--color-primary-element)'
		},
		requiresNote() {
			return this.selectedType ? this.selectedType.requiresNote : false
		},
		needsReplacement() {
			return this.selectedType ? this.selectedType.requiresReplacement : false
		},
		// Bridge the native date pickers (Date objects) to our ISO string state.
		startDate: {
			get() {
				return this.startIso ? new Date(this.startIso + 'T00:00:00') : null
			},
			set(v) {
				this.startIso = v ? toIso(v) : null
				// Keep the end on/after the start.
				if (this.startIso && this.endIso && this.endIso < this.startIso) {
					this.endIso = this.startIso
				}
			},
		},
		endDate: {
			get() {
				return this.endIso ? new Date(this.endIso + 'T00:00:00') : null
			},
			set(v) {
				this.endIso = v ? toIso(v) : null
			},
		},
		subjectUid() {
			// Whose leave this is — that person can't be their own replacement.
			return this.hrMode ? (this.selectedEmployee && this.selectedEmployee.uid) : (this.request ? this.request.employeeUid : store.session.uid)
		},
		workingDaysNum() {
			const v = parseFloat(this.workingDays)
			return Number.isFinite(v) ? v : 0
		},
		balanceRow() {
			// The store holds the current user's balance, so only meaningful for self-service.
			if (!this.selectedType || this.hrMode) {
				return null
			}
			return store.balance.balances.find((b) => b.typeId === this.selectedType.id && b.entitlement !== null) || null
		},
		projectedAvailable() {
			if (!this.balanceRow) {
				return null
			}
			return Math.round((this.balanceRow.available - this.workingDaysNum) * 10) / 10
		},
		wouldGoNegative() {
			return this.balanceRow && this.selectedType && this.selectedType.countsAgainstBalance && this.projectedAvailable < 0
		},
		availablePct() {
			if (!this.balanceRow || !this.balanceRow.entitlement) {
				return 0
			}
			return Math.max(0, Math.min(100, (this.projectedAvailable / this.balanceRow.entitlement) * 100))
		},
		canSubmit() {
			if (!this.selectedType || !this.startIso || !this.endIso || this.workingDaysNum <= 0) {
				return false
			}
			if (this.hrMode && !this.selectedEmployee) {
				return false
			}
			if (this.needsReplacement && !this.selectedReplacement) {
				return false
			}
			if (this.requiresNote && this.reason.trim() === '') {
				return false
			}
			return true
		},
	},
	async mounted() {
		await this.initFromProps()
		if (!this.hrMode && !store.balance.balances.length) {
			await store.loadMyBalance()
		}
	},
	methods: {
		t,
		formatDays(v) {
			if (v === null || v === undefined) {
				return '—'
			}
			return Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 })
		},
		async initFromProps() {
			const types = this.typeOptions
			if (this.request) {
				this.selectedType = store.enabledLeaveTypes.find((x) => x.id === this.request.typeId) || types[0]
				this.startIso = this.request.startDate
				this.endIso = this.request.endDate
				this.workingDays = String(this.request.workingDays)
				this.reason = this.request.reason || ''
				if (this.request.replacementUid) {
					this.selectedReplacement = { uid: this.request.replacementUid, displayName: this.request.replacementName || this.request.replacementUid }
				}
			} else {
				this.selectedType = types[0] || null
				const today = toIso(new Date())
				this.startIso = today
				this.endIso = today
			}
		},
		async onEmployeeSearch(query) {
			if (!query || query.length < 2) {
				return
			}
			this.employeeLoading = true
			try {
				this.employeeOptions = await api.searchUsers(query)
			} catch (e) {
				this.employeeOptions = []
			} finally {
				this.employeeLoading = false
			}
		},
		async onReplacementSearch(query) {
			if (!query || query.length < 2) {
				return
			}
			this.replacementLoading = true
			try {
				const users = await api.searchUsers(query)
				// A person can't be their own replacement.
				this.replacementOptions = users.filter((u) => u.uid !== this.subjectUid)
			} catch (e) {
				this.replacementOptions = []
			} finally {
				this.replacementLoading = false
			}
		},
		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.submitting = true
			const payload = {
				typeId: this.selectedType.id,
				startDate: this.startIso,
				endDate: this.endIso,
				workingDays: this.workingDaysNum,
				reason: this.reason,
			}
			if (this.hrMode && this.selectedEmployee) {
				payload.employeeUid = this.selectedEmployee.uid
			}
			if (this.needsReplacement && this.selectedReplacement) {
				payload.replacementUid = this.selectedReplacement.uid
			}
			try {
				if (this.isEdit) {
					await store.updateRequest(this.request.id, payload)
				} else {
					await store.createRequest(payload)
				}
				this.$emit('saved')
			} catch (e) {
				showError(e.response?.data?.message || t('absence', 'Could not save the request'))
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.dialog {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	padding: calc(var(--default-grid-baseline, 4px) * 5);

	&__title {
		margin: 0 0 4px;
		font-size: 1.3rem;
	}

	&__row {
		display: flex;
		gap: calc(var(--default-grid-baseline, 4px) * 3);
		flex-wrap: wrap;

		.dialog__field {
			flex: 1 1 140px;
		}
	}

	&__field {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__label {
		font-weight: 600;
		font-size: 0.9rem;
	}

	&__req {
		color: var(--color-error);
	}

	&__optional {
		font-weight: normal;
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
	}

	&__hint {
		margin: 4px 0 0;
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		margin-top: 4px;
	}
}

.preview {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: calc(var(--default-grid-baseline, 4px) * 3);
	border-radius: var(--border-radius-large, 12px);
	background: color-mix(in srgb, var(--type-color) 12%, var(--color-main-background));
	border: 2px solid color-mix(in srgb, var(--type-color) 40%, transparent);

	&__days {
		display: flex;
		flex-direction: column;
		line-height: 1.1;
	}

	&__count {
		font-size: 1.8rem;
		font-weight: 700;
		color: var(--type-color);
	}

	&__caption {
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
	}

	&__balance {
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 4px;
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
	}

	&__bar {
		display: block;
		width: 120px;
		height: 6px;
		border-radius: 3px;
		background: var(--color-background-dark);
		overflow: hidden;
	}

	&__bar-fill {
		display: block;
		height: 100%;
		border-radius: 3px;
		background: var(--type-color);
		transition: width 300ms ease;
	}
}

.opt {
	display: inline-flex;
	align-items: center;
	gap: 8px;

	&__icon {
		font-size: 1.1em;
	}
}
</style>
