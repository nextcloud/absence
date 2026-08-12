<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page">
		<header class="page__header">
			<h2 class="page__title">
				{{ t('absence', 'Balances') }}
			</h2>
			<div class="page__tools">
				<NcTextField
					v-model="search"
					:label="t('absence', 'Search employee')"
					class="page__search">
					<template #icon>
						<Magnify :size="18" />
					</template>
				</NcTextField>
				<NcSelect
					v-model="year"
					:options="years"
					:clearable="false"
					:aria-label-combobox="t('absence', 'Year')" />
			</div>
		</header>

		<SkeletonList v-if="loading" :rows="6" />

		<template v-else>
			<div v-if="tiles.length" class="tiles">
				<StatTile
					v-for="tile in tiles"
					:key="tile.label"
					:value="tile.value"
					:label="tile.label"
					:caption="tile.caption"
					:icon="tile.icon"
					:accent="tile.accent" />
			</div>

			<div class="table-wrap">
				<table class="tbl">
					<thead>
						<tr>
							<th>{{ t('absence', 'Employee') }}</th>
							<th>{{ t('absence', 'Type') }}</th>
							<th class="bar-col">
								{{ t('absence', 'Used of entitlement') }}
							</th>
							<th class="num">
								{{ t('absence', 'Entitlement') }}
							</th>
							<th class="num">
								{{ t('absence', 'Used') }}
							</th>
							<th class="num">
								{{ t('absence', 'Pending') }}
							</th>
							<th class="num">
								{{ t('absence', 'Remaining') }}
							</th>
							<th class="num">
								{{ t('absence', 'Available') }}
							</th>
							<th />
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in filtered" :key="row.employeeUid + '-' + row.typeId">
							<td>
								<div class="emp">
									<NcAvatar
										:user="row.employeeUid"
										:displayName="row.displayName"
										:size="24"
										hideStatus /> {{ row.displayName }}
								</div>
							</td>
							<td><span class="type"><span aria-hidden="true">{{ row.typeIcon }}</span> {{ row.typeLabel }}</span></td>
							<td class="bar-col">
								<!-- Only meaningful where there is an entitlement to use up:
							     unpaid and special leave have no ceiling to fill. -->
								<MeterBar
									v-if="row.countsAgainstBalance && row.entitlement > 0"
									:value="row.used || 0"
									:max="row.entitlement"
									:color="barColor(row)"
									:ariaLabel="usedLabel(row)" />
								<span v-else class="bar-col__none" aria-hidden="true">—</span>
							</td>
							<td class="num">
								{{ fmt(row.entitlement) }}
							</td>
							<td class="num">
								{{ fmt(row.used) }}
							</td>
							<td class="num">
								{{ fmt(row.pending) }}
							</td>
							<td class="num">
								{{ fmt(row.remaining) }}
							</td>
							<!-- The column HR actually came for, so it carries the weight and
						     the only colour in the row: red overdrawn, amber nearly spent. -->
							<td class="num lead" :class="availableClass(row)">
								{{ fmt(row.available) }}
							</td>
							<td>
								<NcButton
									v-if="row.countsAgainstBalance"
									variant="tertiary"
									:aria-label="t('absence', 'Edit entitlement')"
									@click="edit(row)">
									<template #icon>
										<Pencil :size="18" />
									</template>
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
				<NcEmptyContent
					v-if="!filtered.length"
					:name="search ? t('absence', 'No matches') : t('absence', 'No balances yet')"
					:description="search ? t('absence', 'No employee matches “{query}”.', { query: search }) : t('absence', 'Balances appear here once employees have entitlements for {year}.', { year })">
					<template #icon>
						<ScaleBalance :size="20" />
					</template>
				</NcEmptyContent>
			</div>
		</template>

		<NcModal v-if="editing" :name="t('absence', 'Edit entitlement')" @close="editing = null">
			<div class="edit">
				<h3>{{ editing.displayName }} · {{ editing.typeLabel }} · {{ year }}</h3>
				<label>{{ t('absence', 'Base days') }}</label>
				<NcTextField v-model="form.baseDays" type="number" />
				<label>{{ t('absence', 'Adjust by (+/−)') }}</label>
				<NcTextField
					v-model="form.adjustmentDelta"
					type="number"
					:placeholder="t('absence', 'e.g. 2 to add two days, -2 to take them back')" />
				<!-- The running total, so it is clear the field above adds to this rather
				     than replacing it — entering −2 to undo an earlier +2 used to set the
				     total to −2 and take two days off the allowance instead. -->
				<p class="edit__hint">
					{{ t('absence', 'Adjustments so far: {total} · leaving the field empty changes nothing', { total: signed(currentAdjustment) }) }}
				</p>
				<label>{{ t('absence', 'Adjustment note') }}</label>
				<NcTextField v-model="form.adjustmentNote" :placeholder="t('absence', 'Why is this being adjusted?')" />
				<div class="edit__actions">
					<NcButton variant="tertiary" @click="editing = null">
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
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import MeterBar from '../../components/MeterBar.vue'
import SkeletonList from '../../components/SkeletonList.vue'
import StatTile from '../../components/StatTile.vue'
import api from '../../api.js'

// Below this share of the entitlement, a balance is worth flagging rather than
// simply reporting — the number is still fine, but it is nearly spent.
const LOW_BALANCE_RATIO = 0.2

export default {
	name: 'HrBalances',
	components: { NcAvatar, NcButton, NcEmptyContent, NcModal, NcSelect, NcTextField, Magnify, Pencil, ScaleBalance, SkeletonList, StatTile, MeterBar },
	data() {
		const y = new Date().getFullYear()
		return {
			loading: true,
			rows: [],
			search: '',
			year: y,
			years: [y - 1, y, y + 1],
			editing: null,
			saving: false,
			form: { baseDays: 0, adjustmentDelta: '', adjustmentNote: '' },
			currentAdjustment: 0,
			history: [],
			historyLoading: false,
		}
	},

	computed: {
		filtered() {
			const q = this.search.trim().toLowerCase()
			if (!q) {
				return this.rows
			}
			return this.rows.filter((r) => r.displayName.toLowerCase().includes(q) || r.employeeUid.toLowerCase().includes(q))
		},

		/**
		 * The four questions this table gets opened to answer, lifted out of it so
		 * they do not have to be totted up by eye. Counted over the *filtered* rows,
		 * so narrowing to a search or a team re-answers them for that group.
		 *
		 * Only types that count against a balance are summed: adding unpaid leave,
		 * which has no entitlement, into "days available" would be meaningless.
		 */
		tiles() {
			const counting = this.filtered.filter((r) => r.countsAgainstBalance && r.entitlement !== null)
			if (!counting.length) {
				return []
			}
			const sum = (key) => counting.reduce((total, r) => total + (r[key] || 0), 0)
			const lowOrOverdrawn = counting.filter((r) => this.isLow(r) || (r.available ?? 0) < 0)
			const people = new Set(this.filtered.map((r) => r.employeeUid))

			return [
				{
					value: people.size,
					label: t('absence', 'employees'),
					caption: t('absence', 'in {year}', { year: this.year }),
					icon: '👥',
					accent: 'var(--color-primary-element)',
				},
				{
					value: this.fmt(sum('used')),
					label: t('absence', 'days taken'),
					caption: t('absence', 'approved so far'),
					icon: '🏖️',
					accent: 'var(--color-success)',
				},
				{
					value: this.fmt(sum('available')),
					label: t('absence', 'days still available'),
					caption: t('absence', 'across the company'),
					icon: '📅',
					accent: 'var(--color-info, var(--color-primary-element))',
				},
				{
					value: new Set(lowOrOverdrawn.map((r) => r.employeeUid)).size,
					label: t('absence', 'running low'),
					caption: t('absence', 'under {pct}% left, or overdrawn', { pct: Math.round(LOW_BALANCE_RATIO * 100) }),
					icon: '⚠️',
					accent: 'var(--color-warning)',
				},
			]
		},
	},

	watch: {
		year() {
			this.reload()
		},
	},

	mounted() {
		this.reload()
	},

	methods: {
		t,
		fmt(v) {
			return v === null || v === undefined ? '—' : Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 })
		},

		/**
		 * Nearly spent, but not yet overdrawn.
		 *
		 * @param row
		 */
		isLow(row) {
			if (!row.countsAgainstBalance || !(row.entitlement > 0)) {
				return false
			}
			const available = row.available ?? 0
			return available >= 0 && available < row.entitlement * LOW_BALANCE_RATIO
		},

		availableClass(row) {
			if ((row.available ?? 0) < 0) {
				return 'neg'
			}
			return this.isLow(row) ? 'low' : ''
		},

		/**
		 * The bar turns amber once the balance is nearly gone, so the warning is in
		 * the shape as well as the number — the type colour is decoration until the
		 * row needs attention, at which point it should stop being decoration.
		 *
		 * @param row
		 */
		barColor(row) {
			if ((row.available ?? 0) < 0) {
				return 'var(--color-error)'
			}
			if (this.isLow(row)) {
				return 'var(--color-warning)'
			}
			return row.typeColor || 'var(--color-primary-element)'
		},

		usedLabel(row) {
			return t('absence', '{used} of {entitlement} days used', {
				used: this.fmt(row.used),
				entitlement: this.fmt(row.entitlement),
			})
		},

		async reload() {
			this.loading = true
			try {
				this.rows = await api.reportBalances(this.year)
			} catch {
				showError(t('absence', 'Could not load balances'))
			} finally {
				this.loading = false
			}
		},

		async edit(row) {
			this.editing = row
			this.history = []
			// Ensure an entitlement row exists to edit; fetch current values.
			try {
				const list = await api.listEntitlements(row.employeeUid, this.year)
				const ent = list.find((e) => e.typeId === row.typeId)
				this.currentAdjustment = ent ? ent.manualAdjustment : (row.manualAdjustment || 0)
				this.form = {
					baseDays: ent ? ent.baseDays : row.baseDays,
					// Always empty: this field is the correction to apply, not the total.
					adjustmentDelta: '',
					adjustmentNote: '',
					entitlementId: ent ? ent.id : row.entitlementId,
				}
			} catch {
				this.currentAdjustment = row.manualAdjustment || 0
				this.form = { baseDays: row.baseDays, adjustmentDelta: '', adjustmentNote: '', entitlementId: row.entitlementId }
			}
			await this.loadHistory()
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
						employeeUid: this.editing.employeeUid,
						year: this.year,
						typeId: this.editing.typeId,
						...data,
					})
				}
				showSuccess(t('absence', 'Entitlement updated'))
				this.editing = null
				await this.reload()
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
.emp, .type {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}

.bar-col {
	width: 15%;
	min-width: 90px;

	&__none {
		color: var(--color-text-maxcontrast);
	}
}

// Amber, not red: the balance is nearly spent, which is worth noticing but is
// not yet the error that an overdrawn one is. Qualified with the element so it
// outranks the global `.tbl .lead` colour on the same cell.
td.low {
	color: var(--color-warning);
}

.edit {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: calc(var(--default-grid-baseline, 4px) * 5);

	label {
		font-weight: 600;
		font-size: 0.85rem;
	}

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
