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

<script setup lang="ts">
import type { ChipStatus } from '../types/index.ts'

import { computed } from 'vue'
import { statusMeta } from '../utils/status.ts'

const props = defineProps<{
	status: ChipStatus
}>()

const meta = computed(() => statusMeta(props.status))
</script>

<style scoped lang="scss">
.status-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill, 16px);
	font-size: var(--font-size-small);
	font-weight: 600;
	// Contrast-optimised text on a solid tinted background (not transparent), so
	// readability doesn't depend on whatever surface sits behind the chip. No
	// border: tinted from each status colour, it stood out on the grey chips and
	// vanished on the coloured ones.
	color: var(--chip-text);
	background: color-mix(in srgb, var(--chip-tint) 18%, var(--color-main-background));
	white-space: nowrap;

	&__dot {
		font-size: 0.85em;
		line-height: 1;
	}
}
</style>
