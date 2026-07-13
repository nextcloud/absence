<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span class="type-chip" :style="{ '--type-color': type.color }">
		<span class="type-chip__icon" aria-hidden="true">{{ type.icon }}</span>
		{{ type.label }}
	</span>
</template>

<script>
import { store } from '../store.js'

export default {
	name: 'LeaveTypeChip',
	props: {
		typeId: { type: Number, required: true },
	},
	computed: {
		type() {
			return store.leaveType(this.typeId)
		},
	},
}
</script>

<style scoped lang="scss">
.type-chip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill, 16px);
	font-size: 0.8rem;
	font-weight: 600;
	// The type colour is arbitrary (set by HR), so pull the text toward the
	// foreground colour for guaranteed contrast in both light and dark themes,
	// and use a solid tinted background rather than a faint transparent one.
	color: color-mix(in srgb, var(--type-color) 50%, var(--color-main-text));
	background: color-mix(in srgb, var(--type-color) 16%, var(--color-main-background));
	border: 1px solid color-mix(in srgb, var(--type-color) 35%, transparent);
	white-space: nowrap;

	&__icon {
		font-size: 0.95em;
		line-height: 1;
	}
}
</style>
