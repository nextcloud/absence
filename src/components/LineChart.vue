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
			:viewBox="`0 0 ${width} ${height}`"
			class="line__svg"
			role="img"
			:aria-label="title"
			preserveAspectRatio="none">
			<line
				v-for="(g, i) in gridlines"
				:key="'g' + i"
				class="line__grid"
				:x1="padX"
				:x2="width - padX"
				:y1="g"
				:y2="g" />
			<path class="line__area" :d="areaPath" />
			<path ref="line" class="line__stroke" :d="linePath" />
			<g v-for="(p, i) in points" :key="'p' + i">
				<circle
					class="line__dot"
					:cx="p.x"
					:cy="p.y"
					r="3" />
				<text
					v-if="showLabel(i)"
					class="line__xlabel"
					:x="p.x"
					:y="height - 4"
					text-anchor="middle">{{ p.label }}</text>
			</g>
		</svg>
	</figure>
</template>

<script>
export default {
	name: 'LineChart',
	props: {
		title: { type: String, default: '' },
		data: { type: Array, required: true }, // [{ label, value }]
	},

	data() {
		return { width: 640, height: 200, padX: 28, padTop: 16, padBottom: 22 }
	},

	computed: {
		max() { return Math.max(1, ...this.data.map((d) => d.value)) },
		points() {
			const n = this.data.length
			const usableW = this.width - this.padX * 2
			const usableH = this.height - this.padTop - this.padBottom
			return this.data.map((d, i) => ({
				x: this.padX + (n <= 1 ? usableW / 2 : (usableW * i) / (n - 1)),
				y: this.padTop + usableH * (1 - d.value / this.max),
				label: d.label,
				value: d.value,
			}))
		},

		linePath() {
			return this.points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ')
		},

		areaPath() {
			if (!this.points.length) {
				return ''
			}
			const base = this.height - this.padBottom
			const first = this.points[0]
			const last = this.points[this.points.length - 1]
			return `M${first.x} ${base} ` + this.points.map((p) => `L${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ') + ` L${last.x} ${base} Z`
		},

		gridlines() {
			const usableH = this.height - this.padTop - this.padBottom
			return [0, 0.5, 1].map((f) => this.padTop + usableH * f)
		},
	},

	methods: {
		showLabel(i) {
			// Avoid crowding: with many points, label every other one.
			const step = this.data.length > 8 ? 2 : 1
			return i % step === 0
		},
	},
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
		width: 100%;
		height: auto;
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
		font-size: 10px;
		fill: var(--color-text-maxcontrast);
	}
}
</style>
