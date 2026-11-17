<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - A headline figure with its label — the tile used across the HR overviews.
  - Every tile looks the same: the icon and the label tell them apart.
-->
<template>
	<div class="tile">
		<span v-if="$slots.icon" class="tile__icon" aria-hidden="true">
			<slot name="icon" />
		</span>
		<span class="tile__value">{{ value }}</span>
		<span class="tile__label">{{ label }}</span>
		<span v-if="caption" class="tile__caption">{{ caption }}</span>
	</div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
	/** The figure itself, already formatted and localised by the caller. */
	value: string | number
	label: string
	/** Optional second line, for context the label has no room for. */
	caption?: string
}>(), {
	caption: '',
})
</script>

<style scoped lang="scss">
.tile {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);

	&__icon {
		display: flex;
		color: var(--color-text-maxcontrast);
		margin-bottom: 6px;
	}

	&__value {
		font-size: 2rem;
		font-weight: 700;
		line-height: 1.1;
		color: var(--color-main-text);
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
