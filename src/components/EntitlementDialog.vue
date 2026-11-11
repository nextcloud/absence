<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal :name="t('absence', 'Edit entitlement')" @close="$emit('close')">
		<div class="edit">
			<h3>{{ displayName }} · {{ row.typeLabel }} · {{ year }}</h3>
			<NcTextField v-model="form.baseDays" type="number" :label="t('absence', 'Base days')" />
			<NcTextField
				v-model="form.adjustmentDelta"
				type="number"
				:label="t('absence', 'Adjust by (+/−)')"
				:placeholder="t('absence', 'e.g. 2 to add two days, -2 to take them back')" />
			<!-- The running total, so it is clear the field above adds to this rather
			     than replacing it: entering −2 to undo an earlier +2 used to set the
			     total to −2 and take two days off the allowance instead. -->
			<p class="edit__hint">
				{{ t('absence', 'Adjustments so far: {total} · leaving the field empty changes nothing', { total: signed(currentAdjustment) }) }}
			</p>
			<NcTextField
				:modelValue="form.adjustmentNote"
				:label="t('absence', 'Adjustment note')"
				:placeholder="t('absence', 'Why is this being adjusted?')"
				@update:modelValue="form.adjustmentNote = String($event)" />
			<div class="edit__actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('absence', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{ t('absence', 'Save') }}
				</NcButton>
			</div>

			<!-- Why this allowance is what it is. The note HR is made to write when
			     adjusting used to be stored and shown to nobody; this is where it
			     lands, next to the figure it explains. -->
			<section class="log">
				<h4 class="log__title">
					{{ t('absence', 'Change history') }}
				</h4>
				<p v-if="historyLoading" class="log__empty">
					{{ t('absence', 'Loading…') }}
				</p>
				<ol v-else-if="history.length" class="log__list">
					<li v-for="ev in history" :key="ev.id" class="log__item">
						<span class="log__delta" :class="ev.delta < 0 ? 'log__delta--down' : 'log__delta--up'">
							{{ ev.delta > 0 ? '+' : '−' }}{{ fmt(Math.abs(ev.delta)) }}
						</span>
						<div class="log__body">
							<span class="log__what">
								{{ fieldLabel(ev.field) }} {{ fmt(ev.oldValue) }} → {{ fmt(ev.newValue) }}
							</span>
							<span v-if="ev.note" class="log__note">{{ ev.note }}</span>
							<span class="log__meta">{{ ev.actorUid === 'system' ? t('absence', 'Automatically') : (ev.actorName || ev.actorUid || t('absence', 'An administrator')) }} · {{ formatDateTime(ev.createdAt) }}</span>
						</div>
					</li>
				</ol>
				<p v-else class="log__empty">
					{{ t('absence', 'No changes recorded yet.') }}
				</p>
			</section>
		</div>
	</NcModal>
</template>

<script setup lang="ts">
import type { BalanceRow, EntitlementEvent, EntitlementField } from '../types/index.ts'
import type { EntitlementForm } from '../utils/entitlement.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import api from '../services/api.ts'
import { entitlementPayload } from '../utils/entitlement.ts'
import { serverMessage } from '../utils/errors.ts'
import { formatNumber, locale } from '../utils/locale.ts'

/**
 * HR's editor for one entitlement of one employee and year, with its change
 * history.
 */

/** The fields as typed, and the stored entitlement they belong to: null while none is stored */
interface EntitlementDialogForm extends EntitlementForm {
	entitlementId: number | null
}

const props = defineProps<{
	uid: string
	displayName: string
	/** The balance row of the leave type to edit */
	row: BalanceRow
	year: number
}>()

const emit = defineEmits<{
	close: []
	saved: []
}>()

const saving = ref(false)
const form = reactive<EntitlementDialogForm>({ baseDays: props.row.baseDays, adjustmentDelta: '', adjustmentNote: '', entitlementId: props.row.entitlementId })
const storedBaseDays = ref(props.row.baseDays)
const currentAdjustment = ref(props.row.manualAdjustment || 0)
const history = ref<EntitlementEvent[]>([])
const historyLoading = ref(false)

onMounted(async () => {
	// Ensure an entitlement row exists to edit; fetch current values.
	try {
		const list = await api.listEntitlements(props.uid, props.year)
		const ent = list.find((e) => e.typeId === props.row.typeId)
		if (ent) {
			currentAdjustment.value = ent.manualAdjustment
			form.baseDays = ent.baseDays
			storedBaseDays.value = ent.baseDays
			form.entitlementId = ent.id
		}
	} catch {
		// The values of the balance row stay in the form.
	}
	await loadHistory()
})

/**
 * @param v a day count, null or undefined for none
 */
function fmt(v: number | null | undefined): string {
	return v === null || v === undefined ? '—' : formatNumber(v)
}

/**
 * The change log for the entitlement being edited. Newest first, because the
 * most recent change is the one that explains the number on screen. Silent on
 * failure: history is context, and losing it must not block the edit itself.
 */
async function loadHistory() {
	if (!form.entitlementId) {
		// Nothing saved yet, so there is nothing to have changed.
		return
	}
	historyLoading.value = true
	try {
		const events = await api.entitlementHistory(form.entitlementId)
		history.value = events.reverse()
	} catch {
		history.value = []
	} finally {
		historyLoading.value = false
	}
}

/**
 * A signed day count, so "+2" and "−2" read as corrections rather than totals.
 *
 * @param value the accumulated adjustment
 */
function signed(value: number): string {
	const n = Number(value) || 0
	return (n > 0 ? '+' : n < 0 ? '−' : '') + fmt(Math.abs(n))
}

/**
 * @param field the figure of the entitlement that changed
 */
function fieldLabel(field: EntitlementField): string {
	return {
		base_days: t('absence', 'Base days'),
		carry_over_days: t('absence', 'Carry-over'),
		manual_adjustment: t('absence', 'Adjustment'),
	}[field] || field
}

/**
 * @param iso an ISO 8601 timestamp, null for none
 */
function formatDateTime(iso: string | null): string {
	return iso ? new Date(iso).toLocaleString(locale(), { dateStyle: 'medium', timeStyle: 'short' }) : ''
}

/**
 * Updates the stored entitlement, or creates it when there is none yet.
 */
async function save() {
	const data = entitlementPayload(form, storedBaseDays.value)
	if (data === null) {
		showError(t('absence', 'Enter the base days as a number.'))
		return
	}
	saving.value = true
	try {
		if (form.entitlementId) {
			await api.updateEntitlement(form.entitlementId, data)
		} else {
			await api.createEntitlement({
				employeeUid: props.uid,
				year: props.year,
				typeId: props.row.typeId,
				...data,
			})
		}
		showSuccess(t('absence', 'Entitlement updated'))
		emit('saved')
	} catch (e) {
		showError(serverMessage(e) || t('absence', 'Could not update entitlement'))
	} finally {
		saving.value = false
	}
}
</script>

<style scoped lang="scss">
.edit {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: calc(var(--default-grid-baseline, 4px) * 5);

	&__actions {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		margin-top: 8px;
	}

	&__hint {
		margin: -4px 0 0;
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
	}
}

.log {
	margin-top: 4px;
	border-top: 1px solid var(--color-border);
	padding-top: 12px;

	&__title {
		margin: 0 0 8px;
		font-size: 0.9rem;
		color: var(--color-text-maxcontrast);
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: 10px;
		margin: 0;
		padding: 0;
		list-style: none;
		// Long-serving employees accumulate corrections; keep the dialog usable.
		max-height: 220px;
		overflow-y: auto;
	}

	&__item {
		display: flex;
		align-items: flex-start;
		gap: 10px;
	}

	&__delta {
		flex: 0 0 auto;
		min-width: 3.2em;
		text-align: end;
		font-weight: bold;
		font-variant-numeric: tabular-nums;

		&--up { color: var(--color-success-text); }
		&--down { color: var(--color-error-text); }
	}

	&__body {
		display: flex;
		flex-direction: column;
		gap: 1px;
	}

	&__note {
		font-style: italic;
	}

	&__meta,
	&__empty {
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
	}

	&__empty {
		margin: 0;
	}
}
</style>
