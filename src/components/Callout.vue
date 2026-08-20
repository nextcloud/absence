<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - A tinted, left-bordered box that lifts a tip, note, privacy point or warning
  - out of the surrounding prose. Theme-aware via color-mix.
-->
<template>
	<div class="callout" :class="`callout--${type}`" role="note">
		<span class="callout__icon" aria-hidden="true">{{ icon }}</span>
		<div class="callout__body">
			<slot />
		</div>
	</div>
</template>

<script>
const ICONS = { tip: '💡', note: 'ℹ️', privacy: '🔒', warning: '⚠️' }

export default {
	name: 'Callout',
	props: {
		type: {
			type: String,
			default: 'note',
			validator: (v) => ['tip', 'note', 'privacy', 'warning'].includes(v),
		},
	},

	computed: {
		icon() {
			return ICONS[this.type] || ICONS.note
		},
	},
}
</script>

<style scoped lang="scss">
.callout {
	--c: var(--color-primary-element);
	display: flex;
	gap: 12px;
	align-items: flex-start;
	max-width: 720px;
	margin: 16px 0;
	padding: 12px 16px;
	border-radius: var(--border-radius-large, 12px);
	border-inline-start: 4px solid var(--c);
	background: color-mix(in srgb, var(--c) 11%, var(--color-main-background));

	&__icon {
		font-size: 1.15rem;
		line-height: 1.5;
	}

	&__body {
		line-height: 1.55;

		:deep(p) { margin: 0 0 6px; }
		:deep(p:last-child) { margin: 0; }
		:deep(strong) { font-weight: 600; }
	}

	&--tip { --c: var(--color-success, #4caf50); }
	&--note { --c: var(--color-primary-element); }
	&--privacy { --c: #7d5ba6; }
	&--warning { --c: var(--color-warning, #c98a1e); }
}
</style>
