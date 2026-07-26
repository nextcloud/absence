<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span class="status-chip" :style="{ '--chip-text': meta.text, '--chip-tint': meta.tint }">
		<span class="status-chip__dot" aria-hidden="true">{{ meta.icon }}</span>
		{{ meta.label }}
	</span>
</template>

<script>
import { statusMeta } from '../store.js'

export default {
	name: 'StatusChip',
	props: {
		status: { type: String, required: true },
	},

	computed: {
		meta() {
			return statusMeta(this.status)
		},
	},
}
</script>

<style scoped lang="scss">
.status-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill, 16px);
	font-size: 0.8rem;
	font-weight: 600;
	// Contrast-optimised text on a solid tinted background (not transparent), so
	// readability doesn't depend on whatever surface sits behind the chip.
	color: var(--chip-text);
	background: color-mix(in srgb, var(--chip-tint) 18%, var(--color-main-background));
	border: 1px solid color-mix(in srgb, var(--chip-tint) 35%, transparent);
	white-space: nowrap;

	&__dot {
		font-size: 0.85em;
		line-height: 1;
	}
}
</style>
