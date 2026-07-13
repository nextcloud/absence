<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page">
		<header class="page__header">
			<h2 class="page__title">{{ t('absence', 'Balances') }}</h2>
			<div class="page__tools">
				<NcTextField v-model="search"
					:label="t('absence', 'Search employee')"
					class="page__search">
					<template #icon><Magnify :size="18" /></template>
				</NcTextField>
				<NcSelect v-model="year" :options="years" :clearable="false" :aria-label-combobox="t('absence', 'Year')" />
			</div>
		</header>

		<SkeletonList v-if="loading" :rows="6" />

		<div v-else class="table-wrap">
			<table class="tbl">
				<thead>
					<tr>
						<th>{{ t('absence', 'Employee') }}</th>
						<th>{{ t('absence', 'Type') }}</th>
						<th class="num">{{ t('absence', 'Entitlement') }}</th>
						<th class="num">{{ t('absence', 'Used') }}</th>
						<th class="num">{{ t('absence', 'Pending') }}</th>
						<th class="num">{{ t('absence', 'Remaining') }}</th>
						<th class="num">{{ t('absence', 'Available') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in filtered" :key="row.employeeUid + '-' + row.typeId">
						<td>
							<div class="emp"><NcAvatar :user="row.employeeUid" :display-name="row.displayName" :size="24" :show-user-status="false" /> {{ row.displayName }}</div>
						</td>
						<td><span class="type"><span aria-hidden="true">{{ row.typeIcon }}</span> {{ row.typeLabel }}</span></td>
						<td class="num">{{ fmt(row.entitlement) }}</td>
						<td class="num">{{ fmt(row.used) }}</td>
						<td class="num">{{ fmt(row.pending) }}</td>
						<td class="num">{{ fmt(row.remaining) }}</td>
						<td class="num" :class="{ neg: (row.available ?? 0) < 0 }">{{ fmt(row.available) }}</td>
						<td>
							<NcButton v-if="row.countsAgainstBalance"
								type="tertiary"
								:aria-label="t('absence', 'Edit entitlement')"
								@click="edit(row)">
								<template #icon><Pencil :size="18" /></template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<NcEmptyContent v-if="!filtered.length"
				:name="search ? t('absence', 'No matches') : t('absence', 'No balances yet')"
				:description="search ? t('absence', 'No employee matches “{query}”.', { query: search }) : t('absence', 'Balances appear here once employees have entitlements for {year}.', { year })">
				<template #icon><ScaleBalance :size="20" /></template>
			</NcEmptyContent>
		</div>

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
					<NcButton type="tertiary" @click="editing = null">{{ t('absence', 'Cancel') }}</NcButton>
					<NcButton type="primary" :disabled="saving" @click="save">{{ t('absence', 'Save') }}</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import api from '../../api.js'
import SkeletonList from '../../components/SkeletonList.vue'

export default {
	name: 'HrBalances',
	components: { NcAvatar, NcButton, NcEmptyContent, NcModal, NcSelect, NcTextField, Magnify, Pencil, ScaleBalance, SkeletonList },
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
		async reload() {
			this.loading = true
			try {
				this.rows = await api.reportBalances(this.year)
			} catch (e) {
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
			} catch (e) {
				this.form = { baseDays: row.baseDays, manualAdjustment: 0, adjustmentNote: '', entitlementId: row.entitlementId }
			}
		},
		async save() {
			this.saving = true
			try {
				let id = this.form.entitlementId
				if (!id) {
					// Create it via bulk-set for this single employee's group-less default, then refetch.
					await api.bulkEntitlements({ year: this.year, typeId: this.editing.typeId, baseDays: Number(this.form.baseDays) })
					const list = await api.listEntitlements(this.editing.employeeUid, this.year)
					id = (list.find((e) => e.typeId === this.editing.typeId) || {}).id
				}
				await api.updateEntitlement(id, {
					baseDays: Number(this.form.baseDays),
					manualAdjustment: Number(this.form.manualAdjustment),
					adjustmentNote: this.form.adjustmentNote,
				})
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
.page {
	max-width: 1100px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline, 4px) * 5);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 4);

	&__header {
		display: flex;
		align-items: flex-end;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
	}

	&__title {
		margin: 0;
		font-size: 1.6rem;
	}

	&__tools {
		display: flex;
		gap: 12px;
		align-items: center;
	}

	&__search {
		width: 240px;
	}
}

.table-wrap {
	overflow-x: auto;
}

.tbl {
	width: 100%;
	border-collapse: collapse;

	th, td {
		padding: 10px 12px;
		text-align: left;
		border-bottom: 1px solid var(--color-border);
	}

	th {
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
		text-transform: uppercase;
		letter-spacing: 0.04em;
	}

	.num {
		text-align: right;
		font-variant-numeric: tabular-nums;
	}

	.neg {
		color: var(--color-error);
		font-weight: 600;
	}
}

.emp, .type {
	display: inline-flex;
	align-items: center;
	gap: 8px;
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
