<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - A one-shot confetti burst for a moment worth celebrating (leave approved).
  - Listens for the global `absence:celebrate` event, paints a screenful of
  - falling pieces, and clears itself. Silent under prefers-reduced-motion, and
  - purely decorative (aria-hidden, pointer-events: none) so it never gets in
  - the way of the app underneath.
-->
<template>
	<div v-if="pieces.length" class="confetti" aria-hidden="true">
		<span
			v-for="p in pieces"
			:key="p.id"
			class="confetti__piece"
			:style="p.style" />
	</div>
</template>

<script>
// The leave-type accent palette, so the celebration feels part of the app.
const COLORS = ['#0e7d6e', '#c98a1e', '#2e7da6', '#7d5ba6', '#d65a5a', '#4caf50', '#e0b341']
let uid = 0

export default {
	name: 'Confetti',

	data() {
		return { pieces: [] }
	},

	mounted() {
		window.addEventListener('absence:celebrate', this.burst)
	},

	beforeUnmount() {
		window.removeEventListener('absence:celebrate', this.burst)
		clearTimeout(this.timer)
	},

	methods: {
		burst() {
			// Respect the reader's motion preference — a screen full of moving
			// confetti is exactly what that setting exists to suppress.
			if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
				return
			}
			const count = 90
			const pieces = []
			for (let i = 0; i < count; i++) {
				const size = 6 + Math.random() * 8
				pieces.push({
					id: ++uid,
					style: {
						left: (Math.random() * 100) + '%',
						width: size + 'px',
						height: (size * 0.4) + 'px',
						background: COLORS[i % COLORS.length],
						animationDelay: (Math.random() * 250) + 'ms',
						animationDuration: (1800 + Math.random() * 1400) + 'ms',
						'--drift': ((Math.random() * 2 - 1) * 80) + 'px',
						'--spin': (Math.random() * 720 - 360) + 'deg',
					},
				})
			}
			this.pieces = pieces
			clearTimeout(this.timer)
			// Long enough for the slowest piece (delay + duration) to leave the screen.
			this.timer = setTimeout(() => {
				this.pieces = []
			}, 3600)
		},
	},
}
</script>

<style scoped lang="scss">
.confetti {
	position: fixed;
	inset: 0;
	overflow: hidden;
	pointer-events: none;
	z-index: 100000;

	&__piece {
		position: absolute;
		top: -8vh;
		border-radius: 2px;
		opacity: 0.92;
		animation-name: confetti-fall;
		animation-timing-function: cubic-bezier(0.3, 0.6, 0.7, 1);
		animation-fill-mode: forwards;
	}
}

@keyframes confetti-fall {
	0% { transform: translate(0, -8vh) rotate(0); opacity: 1; }
	100% { transform: translate(var(--drift), 110vh) rotate(var(--spin)); opacity: 0.9; }
}
</style>
