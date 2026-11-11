<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Minimal, theme-aware, accessible SVG bar chart (no external chart dependency).
-->
<template>
	<figure class="chart">
		<figcaption v-if="title" class="chart__title">
			{{ title }}
		</figcaption>
		<svg
			:viewBox="`0 0 ${width} ${height}`"
			class="chart__svg"
			role="img"
			:aria-label="title">
			<g v-for="(bar, i) in bars" :key="i">
				<rect
					:x="bar.x"
					:y="animated ? bar.y : baseline"
					:width="barWidth"
					:height="animated ? bar.h : 0"
					:fill="bar.color"
					rx="4"
					class="chart__bar" />
				<text
					:x="bar.cx"
					:y="height - 4"
					text-anchor="middle"
					class="chart__label">{{ bar.label }}</text>
				<text
					v-if="bar.value > 0"
					:x="bar.cx"
					:y="bar.y - 4"
					text-anchor="middle"
					class="chart__value">{{ fmt(bar.value) }}</text>
			</g>
		</svg>
		<ul class="hidden-visually">
			<li v-for="(item, i) in data" :key="i">
				{{ item.label }}: {{ fmt(item.value) }}
			</li>
		</ul>
	</figure>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { formatNumber } from '../utils/locale.ts'

/** One bar of the chart */
interface BarDatum {
	label: string
	value: number
	color?: string | null
}

const props = withDefaults(defineProps<{
	title?: string
	data: BarDatum[] // [{ label, value, color? }]
}>(), {
	title: '',
})

const width = 640
const height = 220
const padTop = 24
const baseline = height - 22

const animated = ref(false)

const max = computed(() => Math.max(1, ...props.data.map((d) => d.value)))
const slot = computed(() => props.data.length ? width / props.data.length : width)
const barWidth = computed(() => Math.min(48, slot.value * 0.6))
const bars = computed(() => props.data.map((d, i) => {
	const h = (d.value / max.value) * (baseline - padTop)
	const x = i * slot.value + (slot.value - barWidth.value) / 2
	return {
		x,
		cx: x + barWidth.value / 2,
		y: baseline - h,
		h,
		value: d.value,
		label: d.label,
		color: d.color || 'var(--color-primary-element)',
	}
}))

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
.chart {
	margin: 0;

	&__title {
		font-weight: 600;
		margin-bottom: 8px;
	}

	&__svg {
		width: 100%;
		height: auto;
	}

	&__bar {
		transition: height 800ms cubic-bezier(0.4, 0, 0.2, 1), y 800ms cubic-bezier(0.4, 0, 0.2, 1);
	}

	&__label {
		font-size: 11px;
		fill: var(--color-text-maxcontrast);
	}

	&__value {
		font-size: 11px;
		font-weight: 600;
		fill: var(--color-main-text);
	}
}
</style>
