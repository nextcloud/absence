<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Animated donut ring showing used vs. remaining days for one leave type (§15.2).
-->
<template>
	<div class="ring" role="group" :aria-label="ariaLabel">
		<svg viewBox="0 0 120 120" class="ring__svg">
			<circle
				class="ring__track"
				cx="60"
				cy="60"
				:r="radius" />
			<circle
				class="ring__used"
				cx="60"
				cy="60"
				:r="radius"
				:stroke="row.typeColor"
				:stroke-dasharray="circumference"
				:stroke-dashoffset="usedOffset"
				transform="rotate(-90 60 60)" />
			<text x="60" y="54" class="ring__value">{{ remainingLabel }}</text>
			<text x="60" y="74" class="ring__unit">{{ t('absence', 'left') }}</text>
		</svg>
		<div class="ring__label">
			<span class="ring__icon" aria-hidden="true">{{ row.typeIcon }}</span>
			<span class="ring__name">{{ row.typeLabel }}</span>
		</div>
		<div class="ring__meta">
			<template v-if="row.entitlement !== null">
				{{ t('absence', '{used} of {total} used', { used: format(row.used), total: format(row.entitlement) }) }}
			</template>
			<template v-else>
				{{ t('absence', '{used} taken', { used: format(row.used) }) }}
			</template>
			<span v-if="row.pending > 0" class="ring__pending">· {{ t('absence', '{n} pending', { n: format(row.pending) }) }}</span>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { BalanceRow } from '../types/index.ts'

import { t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { countUp } from '../utils/countUp.ts'
import { formatNumber } from '../utils/locale.ts'

const props = defineProps<{
	row: BalanceRow
}>()

const radius = 50
const circumference = 2 * Math.PI * radius

const animated = ref(false)
const tween = ref(0)

const fraction = computed(() => {
	if (!props.row.entitlement || props.row.entitlement <= 0) {
		return props.row.used > 0 ? 1 : 0
	}
	return Math.min(1, Math.max(0, props.row.used / props.row.entitlement))
})

// Animate from full (nothing drawn) to the used fraction.
const usedOffset = computed(() => animated.value ? circumference * (1 - fraction.value) : circumference)

const targetValue = computed(() => {
	if (props.row.remaining !== null && props.row.remaining !== undefined) {
		return Number(props.row.remaining)
	}
	return Number(props.row.used)
})

const remainingLabel = computed(() => format(tween.value))

const ariaLabel = computed(() => `${props.row.typeLabel}: ${remainingLabel.value} ${t('absence', 'days left')}`)

let stopCount: (() => void) | null = null

onMounted(() => {
	if (reducedMotion()) {
		animated.value = true
		tween.value = targetValue.value
		return
	}
	requestAnimationFrame(() => {
		animated.value = true
	})
	showTarget()
})

// The same ring can get another row, such as another year of the same type
watch(targetValue, () => showTarget())

onBeforeUnmount(() => stopCount?.())

/**
 * Whether the person asked the system for less motion
 */
function reducedMotion(): boolean {
	return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/**
 * Bring the figure in the ring to the row's value, counting from what it shows now
 */
function showTarget(): void {
	stopCount?.()
	if (reducedMotion()) {
		tween.value = targetValue.value
		return
	}
	stopCount = countUp(tween.value, targetValue.value, (value) => {
		tween.value = value
	})
}

/**
 * A number of days in the account's locale.
 *
 * @param v the number of days
 */
function format(v: number): string {
	return formatNumber(v)
}
</script>

<style scoped lang="scss">
.ring {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
	padding: calc(var(--default-grid-baseline, 4px) * 3);

	&__svg {
		width: 120px;
		height: 120px;
	}

	&__track {
		fill: none;
		stroke: var(--color-background-darker, var(--color-border));
		stroke-width: 10;
	}

	&__used {
		fill: none;
		stroke-width: 10;
		stroke-linecap: round;
		transition: stroke-dashoffset 900ms cubic-bezier(0.4, 0, 0.2, 1);
	}

	&__value {
		font-size: 26px;
		font-weight: 700;
		text-anchor: middle;
		fill: var(--color-main-text);
	}

	&__unit {
		font-size: 11px;
		text-anchor: middle;
		fill: var(--color-text-maxcontrast);
		text-transform: uppercase;
		letter-spacing: 0.06em;
	}

	&__label {
		display: flex;
		align-items: center;
		gap: 6px;
		font-weight: 600;
	}

	&__meta {
		font-size: 0.82rem;
		color: var(--color-text-maxcontrast);
	}

	&__pending {
		color: var(--color-warning-text, var(--color-warning));
	}
}
</style>
