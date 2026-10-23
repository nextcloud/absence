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
				v-model="form.adjustmentNote"
				:label="t('absence', 'Adjustment note')"
				:placeholder="t('absence', 'Why is this being adjusted?')" />
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
							<span class="log__meta">{{ ev.actorUid }} · {{ formatDateTime(ev.createdAt) }}</span>
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

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import api from '../api.js'

/**
 * HR's editor for one entitlement of one employee and year, with its change
 * history.
 */
export default {
	name: 'EntitlementDialog',
	components: { NcButton, NcModal, NcTextField },
	props: {
		uid: { type: String, required: true },
		displayName: { type: String, required: true },
		/** The balance row of the leave type to edit */
		row: { type: Object, required: true },
		year: { type: Number, required: true },
	},

	emits: ['close', 'saved'],

	data() {
		return {
			saving: false,
			form: { baseDays: this.row.baseDays, adjustmentDelta: '', adjustmentNote: '', entitlementId: this.row.entitlementId },
			currentAdjustment: this.row.manualAdjustment || 0,
			history: [],
			historyLoading: false,
		}
	},

	async mounted() {
		// Ensure an entitlement row exists to edit; fetch current values.
		try {
			const list = await api.listEntitlements(this.uid, this.year)
			const ent = list.find((e) => e.typeId === this.row.typeId)
			if (ent) {
				this.currentAdjustment = ent.manualAdjustment
				this.form.baseDays = ent.baseDays
				this.form.entitlementId = ent.id
			}
		} catch {
			// The values of the balance row stay in the form.
		}
		await this.loadHistory()
	},

	methods: {
		t,
		fmt(v) {
			return v === null || v === undefined ? '—' : Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 })
		},

		/**
		 * The change log for the entitlement being edited. Newest first, because the
		 * most recent change is the one that explains the number on screen. Silent on
		 * failure: history is context, and losing it must not block the edit itself.
		 */
		async loadHistory() {
			if (!this.form.entitlementId) {
				// Nothing saved yet, so there is nothing to have changed.
				return
			}
			this.historyLoading = true
			try {
				const events = await api.entitlementHistory(this.form.entitlementId)
				this.history = events.reverse()
			} catch {
				this.history = []
			} finally {
				this.historyLoading = false
			}
		},

		/**
		 * A signed day count, so "+2" and "−2" read as corrections rather than totals.
		 *
		 * @param {number} value the accumulated adjustment
		 * @return {string}
		 */
		signed(value) {
			const n = Number(value) || 0
			return (n > 0 ? '+' : n < 0 ? '−' : '') + this.fmt(Math.abs(n))
		},

		fieldLabel(field) {
			return {
				base_days: t('absence', 'Base days'),
				carry_over_days: t('absence', 'Carry-over'),
				manual_adjustment: t('absence', 'Adjustment'),
			}[field] || field
		},

		formatDateTime(iso) {
			return iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''
		},

		async save() {
			this.saving = true
			try {
				const delta = Number(this.form.adjustmentDelta)
				const data = {
					baseDays: Number(this.form.baseDays),
					adjustmentNote: this.form.adjustmentNote,
				}
				// Omitted entirely when blank, so saving a base-days change on its own
				// never touches the accumulated adjustment.
				if (this.form.adjustmentDelta !== '' && !Number.isNaN(delta)) {
					data.adjustmentDelta = delta
				}
				if (this.form.entitlementId) {
					await api.updateEntitlement(this.form.entitlementId, data)
				} else {
					await api.createEntitlement({
						employeeUid: this.uid,
						year: this.year,
						typeId: this.row.typeId,
						...data,
					})
				}
				showSuccess(t('absence', 'Entitlement updated'))
				this.$emit('saved')
			} catch (e) {
				showError(e.response?.data?.message || t('absence', 'Could not update entitlement'))
			} finally {
				this.saving = false
			}
		},
	},
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
