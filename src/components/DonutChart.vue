<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Theme-aware SVG donut with legend. No external chart dependency.
-->
<template>
	<figure class="donut">
		<figcaption v-if="title" class="donut__title">
			{{ title }}
		</figcaption>
		<div class="donut__body">
			<svg
				viewBox="0 0 160 160"
				class="donut__svg"
				role="img"
				:aria-label="title">
				<circle
					class="donut__track"
					cx="80"
					cy="80"
					:r="radius" />
				<circle
					v-for="(seg, i) in segments"
					:key="i"
					class="donut__seg"
					cx="80"
					cy="80"
					:r="radius"
					:stroke="seg.color"
					:stroke-dasharray="`${animated ? seg.len : 0} ${circumference}`"
					:stroke-dashoffset="-seg.offset"
					transform="rotate(-90 80 80)">
					<title>{{ seg.label }}: {{ fmt(seg.value) }}</title>
				</circle>
				<text x="80" y="74" class="donut__total">{{ fmt(total) }}</text>
				<text x="80" y="92" class="donut__unit">{{ t('absence', 'days') }}</text>
			</svg>
			<ul class="donut__legend">
				<li v-for="(seg, i) in segments" :key="i">
					<span class="donut__swatch" :style="{ background: seg.color }" />
					<span class="donut__label">{{ seg.label }}</span>
					<span class="donut__value">{{ fmt(seg.value) }} · {{ Math.round(seg.pct) }}%</span>
				</li>
			</ul>
		</div>
	</figure>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import { formatNumber } from '../utils/locale.ts'

/** One slice of the donut */
interface DonutDatum {
	label: string
	value: number
	color: string
}

const props = withDefaults(defineProps<{
	title?: string
	data: DonutDatum[] // [{ label, value, color }]
}>(), {
	title: '',
})

const radius = 64
const circumference = 2 * Math.PI * radius

const animated = ref(false)

const total = computed(() => props.data.reduce((s, d) => s + d.value, 0))

const segments = computed(() => {
	const sum = total.value || 1
	let acc = 0
	return props.data.filter((d) => d.value > 0).map((d) => {
		const pct = (d.value / sum) * 100
		const len = (d.value / sum) * circumference
		const seg = { ...d, pct, len, offset: acc }
		acc += len
		return seg
	})
})

onMounted(() => {
	if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
		animated.value = true
		return
	}
	requestAnimationFrame(() => {
		animated.value = true
	})
})

/**
 * A value in the account's locale.
 *
 * @param v the value to format
 */
function fmt(v: number): string {
	return formatNumber(v)
}
</script>

<style scoped lang="scss">
.donut {
	margin: 0;

	&__title {
		font-weight: 600;
		margin-bottom: 8px;
	}

	&__body {
		display: flex;
		align-items: center;
		gap: 20px;
		flex-wrap: wrap;
	}

	&__svg {
		width: 160px;
		height: 160px;
		flex: 0 0 auto;
	}

	&__track {
		fill: none;
		stroke: var(--color-background-dark);
		stroke-width: 18;
	}

	&__seg {
		fill: none;
		stroke-width: 18;
		stroke-linecap: butt;
		transition: stroke-dasharray 800ms cubic-bezier(0.4, 0, 0.2, 1);
	}

	&__total {
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

	&__legend {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
		min-width: 160px;

		li {
			display: flex;
			align-items: center;
			gap: 8px;
			font-size: 0.88rem;
		}
	}

	&__swatch {
		width: 12px;
		height: 12px;
		border-radius: 3px;
		flex: 0 0 auto;
	}

	&__label { flex: 1; }

	&__value { color: var(--color-text-maxcontrast); }
}

@media (prefers-reduced-motion: reduce) {
	.donut__seg { transition: none; }
}
</style>
