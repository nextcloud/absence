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
	</figure>
</template>

<script>
export default {
	name: 'BarChart',
	props: {
		title: { type: String, default: '' },
		data: { type: Array, required: true }, // [{ label, value, color? }]
	},

	data() {
		return { width: 640, height: 220, animated: false }
	},

	computed: {
		padTop() { return 24 },
		baseline() { return this.height - 22 },
		max() { return Math.max(1, ...this.data.map((d) => d.value)) },
		slot() { return this.data.length ? this.width / this.data.length : this.width },
		barWidth() { return Math.min(48, this.slot * 0.6) },
		bars() {
			return this.data.map((d, i) => {
				const h = (d.value / this.max) * (this.baseline - this.padTop)
				const x = i * this.slot + (this.slot - this.barWidth) / 2
				return {
					x,
					cx: x + this.barWidth / 2,
					y: this.baseline - h,
					h,
					value: d.value,
					label: d.label,
					color: d.color || 'var(--color-primary-element)',
				}
			})
		},
	},

	mounted() {
		if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
			this.animated = true
			return
		}
		requestAnimationFrame(() => { this.animated = true })
	},

	methods: {
		fmt(v) { return Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 }) },
	},
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
