<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Balance ring plus a breakdown ledger explaining how the number is composed:
  - base + carryover ± adjustment = entitlement, minus used/pending → available.
-->
<template>
	<div class="card">
		<BalanceRing :row="row" />
		<dl v-if="row.entitlement !== null" class="ledger">
			<div class="ledger__row">
				<dt>{{ t('absence', 'Base allowance') }}</dt>
				<dd>{{ format(row.baseDays) }}</dd>
			</div>
			<div v-if="row.carryOverDays" class="ledger__row">
				<dt>{{ t('absence', 'Carried over') }}</dt>
				<dd>{{ signed(row.carryOverDays) }}</dd>
			</div>
			<div v-if="row.manualAdjustment" class="ledger__row">
				<dt>{{ t('absence', 'Adjustment') }}</dt>
				<dd>{{ signed(row.manualAdjustment) }}</dd>
			</div>
			<div class="ledger__row ledger__row--total">
				<dt>{{ t('absence', 'Entitlement') }}</dt>
				<dd>{{ format(row.entitlement) }}</dd>
			</div>
			<div class="ledger__row">
				<dt>{{ t('absence', 'Used') }}</dt>
				<dd>{{ row.used ? '−' + format(row.used) : format(0) }}</dd>
			</div>
			<div v-if="row.pending" class="ledger__row ledger__row--pending">
				<dt>{{ t('absence', 'Pending approval') }}</dt>
				<dd>{{ '−' + format(row.pending) }}</dd>
			</div>
			<div class="ledger__row ledger__row--available" :style="{ '--type-color': row.typeColor }">
				<dt>{{ t('absence', 'Available') }}</dt>
				<dd>{{ format(row.available) }}</dd>
			</div>
		</dl>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import BalanceRing from './BalanceRing.vue'

export default {
	name: 'BalanceCard',
	components: { BalanceRing },
	props: {
		row: { type: Object, required: true },
	},
	methods: {
		t,
		format(v) {
			return Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 })
		},
		signed(v) {
			const n = Number(v)
			return (n >= 0 ? '+' : '−') + this.format(Math.abs(n))
		},
	},
}
</script>

<style scoped lang="scss">
.card {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
	flex-wrap: wrap;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large, 12px);
	padding: calc(var(--default-grid-baseline, 4px) * 2) calc(var(--default-grid-baseline, 4px) * 3);

	// Compact variant of the ring inside the card.
	:deep(.ring) {
		padding: calc(var(--default-grid-baseline, 4px));
		gap: 2px;
	}

	:deep(.ring__svg) {
		width: 92px;
		height: 92px;
	}

	:deep(.ring__value) {
		font-size: 30px;
	}

	:deep(.ring__unit) {
		font-size: 12px;
	}
}

.ledger {
	flex: 1;
	min-width: 220px;
	max-width: 340px;
	margin: 0;
	// Push the ledger to the right edge of the card.
	margin-inline-start: auto;
	padding: 0;
	font-size: 0.85rem;

	&__row {
		display: flex;
		justify-content: space-between;
		gap: 12px;
		padding: 1px 0;
		color: var(--color-text-maxcontrast);

		// Undo the server-wide dt/dd defaults (12px padding, fixed dt width).
		dt,
		dd {
			padding: 0;
			margin: 0;
			width: auto;
		}

		dt {
			font-weight: normal;
			text-align: start;
		}

		dd {
			font-variant-numeric: tabular-nums;
		}

		&--total {
			border-top: 1px solid var(--color-border);
			color: var(--color-main-text);
			font-weight: 600;
		}

		&--pending {
			color: var(--color-warning-text, var(--color-warning));
		}

		&--available {
			border-top: 1px solid var(--color-border);
			color: var(--color-main-text);
			font-weight: 700;

			dd {
				color: var(--type-color, var(--color-main-text));
			}
		}
	}
}
</style>
