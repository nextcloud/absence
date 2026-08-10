<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Friendly animated illustration for empty states — a palm tree on an island that
  - follows the calendar: blossom in spring, full sun in summer, falling leaves in
  - autumn, snow in winter. Uses theme colours; all motion stops under
  - reduced-motion. Seasons flip for southern-hemisphere users (see `seasonOf`).
-->
<template>
	<svg
		class="palm"
		:class="`palm--${resolvedSeason}`"
		viewBox="0 0 160 140"
		width="160"
		height="140"
		role="img"
		aria-hidden="true">
		<!-- sun (smaller and paler in winter, when it barely gets over the horizon) -->
		<circle
			class="palm__sun"
			cx="126"
			:cy="resolvedSeason === 'winter' ? 42 : 34"
			:r="resolvedSeason === 'winter' ? 11 : 16" />
		<!-- sea -->
		<path class="palm__sea" d="M0 118 Q40 110 80 118 T160 118 V140 H0 Z" />
		<!-- island -->
		<ellipse
			class="palm__island"
			cx="70"
			cy="120"
			rx="46"
			ry="10" />
		<!-- snow settled on the island -->
		<path
			v-if="resolvedSeason === 'winter'"
			class="palm__snow"
			d="M24 120 A46 10 0 0 1 116 120 Z" />
		<!-- trunk -->
		<path class="palm__trunk" d="M68 120 C64 100 66 84 72 70 L78 71 C73 85 72 101 74 120 Z" />
		<!-- fronds -->
		<g class="palm__fronds">
			<path class="palm__frond" d="M74 70 C58 60 44 60 34 66 C48 60 62 64 74 72 Z" />
			<path class="palm__frond" d="M74 70 C60 52 46 46 34 46 C50 50 64 58 75 70 Z" />
			<path class="palm__frond" d="M75 70 C78 52 90 42 104 40 C90 48 80 58 77 71 Z" />
			<path class="palm__frond" d="M76 71 C86 60 102 58 114 64 C100 60 86 62 77 72 Z" />
			<path class="palm__frond" d="M75 69 C74 54 78 44 84 36 C80 48 79 58 78 70 Z" />
		</g>
		<!-- coconuts -->
		<circle
			class="palm__coco"
			cx="72"
			cy="73"
			r="3" />
		<circle
			class="palm__coco"
			cx="79"
			cy="74"
			r="3" />

		<!-- spring: blossom in the fronds and a pair of birds -->
		<g v-if="resolvedSeason === 'spring'" class="palm__blossoms">
			<circle
				v-for="(b, i) in blossoms"
				:key="i"
				class="palm__blossom"
				:cx="b.x"
				:cy="b.y"
				r="2.4"
				:style="{ animationDelay: `${i * 0.4}s` }" />
		</g>
		<path
			v-if="resolvedSeason === 'spring'"
			class="palm__bird"
			d="M20 30 q5 -5 10 0 q5 -5 10 0" />

		<!-- autumn: fronds shedding into the sea -->
		<g v-if="resolvedSeason === 'autumn'" class="palm__falling">
			<ellipse
				v-for="(f, i) in fallers"
				:key="i"
				class="palm__leaf"
				:cx="f.x"
				cy="78"
				rx="4"
				ry="2"
				:style="{ animationDelay: `${f.delay}s` }" />
		</g>

		<!-- winter: snowfall -->
		<g v-if="resolvedSeason === 'winter'" class="palm__falling">
			<circle
				v-for="(f, i) in fallers"
				:key="i"
				class="palm__flake"
				:cx="f.x"
				cy="20"
				r="2"
				:style="{ animationDelay: `${f.delay}s` }" />
		</g>
	</svg>
</template>

<script>
import { store } from '../store.js'
import { seasonOf } from '../utils/dates.js'

export default {
	name: 'PalmIllustration',
	props: {
		/** Force a season; by default it follows today's date and the user's country. */
		season: {
			type: String,
			default: null,
			validator: (v) => v === null || ['winter', 'spring', 'summer', 'autumn'].includes(v),
		},
	},

	data() {
		return {
			// Fixed rather than random so the illustration is identical on every render
			// and in screenshots — the staggered delays supply the variety.
			blossoms: [
				{ x: 46, y: 62 },
				{ x: 62, y: 55 },
				{ x: 88, y: 50 },
				{ x: 104, y: 58 },
				{ x: 80, y: 44 },
			],

			fallers: [
				{ x: 40, delay: 0 },
				{ x: 62, delay: 1.3 },
				{ x: 84, delay: 2.6 },
				{ x: 106, delay: 0.7 },
				{ x: 128, delay: 2 },
			],
		}
	},

	computed: {
		resolvedSeason() {
			return this.season || seasonOf(new Date(), store.session.holidayCountry)
		},
	},
}
</script>

<style scoped lang="scss">
.palm {
	max-width: 160px;
	height: auto;

	&__sun {
		fill: var(--color-warning);
		opacity: 0.85;
		animation: palm-bob 4s ease-in-out infinite;
		transform-origin: center;
	}

	&__sea { fill: color-mix(in srgb, var(--color-primary-element) 30%, transparent); }
	&__island { fill: color-mix(in srgb, var(--color-success) 35%, var(--color-main-background)); }
	&__trunk { fill: color-mix(in srgb, #8a5a2b 70%, var(--color-main-text)); }
	&__coco { fill: #6b3f1d; }

	&__frond {
		fill: var(--color-success);
	}

	&__fronds {
		transform-origin: 75px 70px;
		animation: palm-sway 5s ease-in-out infinite;
	}

	&__blossom {
		fill: #e8829e;
		transform-origin: center;
		animation: palm-bloom 3s ease-in-out infinite;
	}

	&__bird {
		fill: none;
		stroke: var(--color-text-maxcontrast);
		stroke-width: 1.4;
		stroke-linecap: round;
		animation: palm-bob 5s ease-in-out infinite;
	}

	&__leaf,
	&__flake {
		// Without a fill-box origin the rotation would pivot on the SVG's corner and
		// fling the leaf off the canvas instead of tumbling it.
		transform-box: fill-box;
		transform-origin: center;
		animation: palm-fall 6s linear infinite;
	}

	&__leaf { fill: #c2703a; }
	&__flake { fill: var(--color-main-text); opacity: 0.55; }

	&__snow { fill: color-mix(in srgb, var(--color-main-text) 12%, var(--color-main-background)); }

	// Spring — fresh growth, a sun that has not warmed up yet.
	&--spring {
		.palm__frond { fill: color-mix(in srgb, var(--color-success) 80%, #d8f0a0); }
		.palm__sun { opacity: 0.6; }
	}

	// Autumn — the fronds turn before they drop.
	&--autumn {
		.palm__frond { fill: color-mix(in srgb, var(--color-warning) 70%, var(--color-success)); }
		.palm__sea { fill: color-mix(in srgb, var(--color-primary-element) 22%, transparent); }
	}

	// Winter — a snow-capped palm is absurd, which is the point.
	&--winter {
		.palm__frond { fill: color-mix(in srgb, var(--color-success) 55%, var(--color-text-maxcontrast)); }
		.palm__island { fill: color-mix(in srgb, var(--color-success) 18%, var(--color-main-background)); }
		.palm__sun { opacity: 0.45; }
		.palm__sea { fill: color-mix(in srgb, var(--color-primary-element) 18%, transparent); }
	}
}

@keyframes palm-sway {
	0%, 100% { transform: rotate(-2deg); }
	50% { transform: rotate(2deg); }
}

@keyframes palm-bob {
	0%, 100% { transform: translateY(0); }
	50% { transform: translateY(-3px); }
}

@keyframes palm-bloom {
	0%, 100% { transform: scale(0.85); opacity: 0.75; }
	50% { transform: scale(1.1); opacity: 1; }
}

@keyframes palm-fall {
	0% { transform: translate(0, 0) rotate(0deg); opacity: 0; }
	10% { opacity: 1; }
	90% { opacity: 1; }
	100% { transform: translate(-14px, 46px) rotate(180deg); opacity: 0; }
}

@media (prefers-reduced-motion: reduce) {
	.palm__fronds,
	.palm__sun,
	.palm__bird,
	.palm__blossom,
	.palm__leaf,
	.palm__flake { animation: none; }
}
</style>
