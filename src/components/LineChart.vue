<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Theme-aware SVG area/line chart for a time series. No external dependency.
-->
<template>
	<figure class="line">
		<figcaption v-if="title" class="line__title">
			{{ title }}
		</figcaption>
		<svg
			ref="svg"
			:viewBox="`0 0 ${width} ${height}`"
			:height="height"
			class="line__svg"
			role="img"
			:aria-label="title">
			<line
				v-for="(g, i) in gridlines"
				:key="'g' + i"
				class="line__grid"
				:x1="padX"
				:x2="width - padX"
				:y1="g"
				:y2="g" />
			<path class="line__area" :d="areaPath" />
			<path class="line__stroke" :d="linePath" />
			<g v-for="(p, i) in points" :key="'p' + i">
				<circle
					class="line__dot"
					:cx="p.x"
					:cy="p.y"
					r="3" />
				<text
					v-if="i % step === 0"
					class="line__xlabel"
					:x="p.x"
					:y="height - 4"
					text-anchor="middle">{{ p.label }}</text>
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
import { computed, ref } from 'vue'
import { useElementWidth } from '../composables/useElementWidth.ts'
import { labelStep } from '../utils/chartLabels.ts'
import { formatNumber } from '../utils/locale.ts'

/** One point of the series */
interface LineDatum {
	label: string
	value: number
}

const props = withDefaults(defineProps<{
	title?: string
	data: LineDatum[] // [{ label, value }]
}>(), {
	title: '',
})

// The view box matches the rendered size, so text keeps its CSS font size.
const svg = ref<SVGSVGElement | null>(null)
const width = useElementWidth(svg, 640)
const height = 160
const padX = 28
const padTop = 16
const padBottom = 22

const max = computed(() => Math.max(1, ...props.data.map((d) => d.value)))

const usableW = computed(() => width.value - padX * 2)

const points = computed(() => {
	const n = props.data.length
	const usableH = height - padTop - padBottom
	return props.data.map((d, i) => ({
		x: padX + (n <= 1 ? usableW.value / 2 : (usableW.value * i) / (n - 1)),
		y: padTop + usableH * (1 - d.value / max.value),
		label: d.label,
		value: d.value,
	}))
})

const linePath = computed(() => points.value.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' '))

const areaPath = computed(() => {
	const first = points.value.at(0)
	const last = points.value.at(-1)
	if (!first || !last) {
		return ''
	}
	const base = height - padBottom
	return `M${first.x} ${base} ` + points.value.map((p) => `L${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ') + ` L${last.x} ${base} Z`
})

const step = computed(() => labelStep(props.data.map((d) => d.label), props.data.length > 1 ? usableW.value / (props.data.length - 1) : usableW.value))

const gridlines = computed(() => {
	const usableH = height - padTop - padBottom
	return [0, 0.5, 1].map((f) => padTop + usableH * f)
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
.line {
	margin: 0;

	&__title {
		font-weight: 600;
		margin-bottom: 8px;
	}

	&__svg {
		display: block;
		width: 100%;
	}

	&__grid {
		stroke: var(--color-border);
		stroke-width: 1;
	}

	&__area {
		fill: color-mix(in srgb, var(--color-primary-element) 18%, transparent);
	}

	&__stroke {
		fill: none;
		stroke: var(--color-primary-element);
		stroke-width: 2.5;
		stroke-linejoin: round;
		stroke-linecap: round;
	}

	&__dot {
		fill: var(--color-main-background);
		stroke: var(--color-primary-element);
		stroke-width: 2;
	}

	&__xlabel {
		font-size: var(--font-size-small);
		fill: var(--color-text-maxcontrast);
	}
}
</style>
