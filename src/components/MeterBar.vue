<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - A proportional bar for a table cell: how much of `max` this row's `value` is.
  -
  - Its job is to make a column of numbers scannable — the eye finds the long bar
  - far faster than it finds the big number, which is the whole point of putting a
  - table in front of somebody in the first place.
-->
<template>
	<div
		class="meter"
		role="img"
		:aria-label="ariaLabel">
		<div class="meter__track">
			<div
				class="meter__fill"
				:style="{ width: pct + '%', background: color }" />
		</div>
	</div>
</template>

<script>
export default {
	name: 'MeterBar',
	props: {
		value: { type: Number, required: true },
		/** The row the bar is measured against — usually the column's largest. */
		max: { type: Number, required: true },
		color: { type: String, default: 'var(--color-primary-element)' },
		/**
		 * Screen readers get the number from the neighbouring cell, so the bar is
		 * decorative unless the caller gives it something of its own to say.
		 */
		ariaLabel: { type: String, default: '' },
	},

	computed: {
		pct() {
			if (!(this.max > 0) || !(this.value > 0)) {
				return 0
			}
			// Floor at a sliver so a small non-zero value still reads as present
			// rather than as nothing at all.
			return Math.max(2, Math.min(100, (this.value / this.max) * 100))
		},
	},
}
</script>

<style scoped lang="scss">
.meter {
	display: flex;
	align-items: center;
	min-width: 60px;

	&__track {
		width: 100%;
		height: 8px;
		border-radius: 4px;
		background: var(--color-background-dark);
		overflow: hidden;
	}

	&__fill {
		height: 100%;
		border-radius: 4px;
		transition: width 320ms ease;
	}
}

@media (prefers-reduced-motion: reduce) {
	.meter__fill { transition: none; }
}
</style>
