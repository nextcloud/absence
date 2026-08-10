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
				<label>{{ t('absence', 'Manual adjustment (+/−)') }}</label>
				<NcTextField v-model="form.manualAdjustment" type="number" />
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
			form: { baseDays: 0, manualAdjustment: 0, adjustmentNote: '' },
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
			// Ensure an entitlement row exists to edit; fetch current values.
			try {
				const list = await api.listEntitlements(row.employeeUid, this.year)
				const ent = list.find((e) => e.typeId === row.typeId)
				this.form = {
					baseDays: ent ? ent.baseDays : row.baseDays,
					manualAdjustment: ent ? ent.manualAdjustment : 0,
					adjustmentNote: '',
					entitlementId: ent ? ent.id : row.entitlementId,
				}
			} catch {
				this.form = { baseDays: row.baseDays, manualAdjustment: 0, adjustmentNote: '', entitlementId: row.entitlementId }
			}
		},

		async save() {
			this.saving = true
			try {
				const data = {
					baseDays: Number(this.form.baseDays),
					manualAdjustment: Number(this.form.manualAdjustment),
					adjustmentNote: this.form.adjustmentNote,
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
}
</style>
