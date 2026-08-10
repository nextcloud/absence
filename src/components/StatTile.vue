<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - A headline figure with its label — the tile used across the HR overviews.
  -
  - It carries an accent colour rather than painting every tile in the primary
  - element: a row of identical blue numbers gives the eye nothing to hold on to,
  - and the accent is free information (which leave type, good news or bad).
-->
<template>
	<div class="tile" :style="{ '--tile-accent': accent }">
		<span v-if="icon" class="tile__icon" aria-hidden="true">{{ icon }}</span>
		<span class="tile__value">{{ value }}</span>
		<span class="tile__label">{{ label }}</span>
		<span v-if="caption" class="tile__caption">{{ caption }}</span>
	</div>
</template>

<script>
export default {
	name: 'StatTile',
	props: {
		/** The figure itself, already formatted and localised by the caller. */
		value: { type: [String, Number], required: true },
		label: { type: String, required: true },
		/** Optional second line, for context the label has no room for. */
		caption: { type: String, default: '' },
		/** Emoji, matching the leave-type icons used elsewhere. */
		icon: { type: String, default: '' },
		accent: { type: String, default: 'var(--color-primary-element)' },
	},
}
</script>

<style scoped lang="scss">
.tile {
	position: relative;
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	border-radius: var(--border-radius-large, 12px);
	background: color-mix(in srgb, var(--tile-accent) 8%, var(--color-main-background));
	border: 1px solid color-mix(in srgb, var(--tile-accent) 22%, transparent);
	overflow: hidden;

	// A hairline of the accent at full strength: enough to tell tiles apart at a
	// glance without tinting the whole card into unreadability.
	&::before {
		content: '';
		position: absolute;
		inset-block: 0;
		inset-inline-start: 0;
		width: 3px;
		background: var(--tile-accent);
	}

	&__icon {
		font-size: 1.3rem;
		line-height: 1;
		margin-bottom: 6px;
	}

	&__value {
		font-size: 2rem;
		font-weight: 700;
		line-height: 1.1;
		color: var(--tile-accent);
		font-variant-numeric: tabular-nums;
	}

	&__label {
		color: var(--color-main-text);
		font-size: 0.9rem;
	}

	&__caption {
		color: var(--color-text-maxcontrast);
		font-size: 0.8rem;
	}
}
</style>
