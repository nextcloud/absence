<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Friendly animated illustration for empty states — a palm tree on an island with
  - a gently bobbing sun. Uses theme colours; sway is disabled under reduced-motion.
-->
<template>
	<svg
		class="palm"
		viewBox="0 0 160 140"
		width="160"
		height="140"
		role="img"
		aria-hidden="true">
		<!-- sun -->
		<circle
			class="palm__sun"
			cx="126"
			cy="34"
			r="16" />
		<!-- sea -->
		<path class="palm__sea" d="M0 118 Q40 110 80 118 T160 118 V140 H0 Z" />
		<!-- island -->
		<ellipse
			class="palm__island"
			cx="70"
			cy="120"
			rx="46"
			ry="10" />
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
	</svg>
</template>

<script>
export default { name: 'PalmIllustration' }
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
}

@keyframes palm-sway {
	0%, 100% { transform: rotate(-2deg); }
	50% { transform: rotate(2deg); }
}

@keyframes palm-bob {
	0%, 100% { transform: translateY(0); }
	50% { transform: translateY(-3px); }
}

@media (prefers-reduced-motion: reduce) {
	.palm__fronds,
	.palm__sun { animation: none; }
}
</style>
