<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Shimmer placeholder rows shown while data loads — reads more modern than a spinner.
-->
<template>
	<div class="skeleton" :aria-label="t('absence', 'Loading…')" role="status">
		<div v-for="i in rows" :key="i" class="skeleton__row">
			<span class="skeleton__avatar" />
			<span class="skeleton__lines">
				<span class="skeleton__line skeleton__line--title" />
				<span class="skeleton__line skeleton__line--sub" />
			</span>
			<span class="skeleton__pill" />
		</div>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'

export default {
	name: 'SkeletonList',
	props: {
		rows: { type: Number, default: 4 },
	},
	methods: { t },
}
</script>

<style scoped lang="scss">
.skeleton {
	display: flex;
	flex-direction: column;
	gap: 6px;

	&__row {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 10px 8px;
	}

	&__avatar {
		width: 40px;
		height: 40px;
		border-radius: 50%;
		flex: 0 0 auto;
	}

	&__lines {
		flex: 1;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__line {
		height: 12px;
		border-radius: 6px;

		&--title { width: 45%; }
		&--sub { width: 65%; height: 10px; }
	}

	&__pill {
		width: 72px;
		height: 22px;
		border-radius: var(--border-radius-pill, 16px);
		flex: 0 0 auto;
	}

	&__avatar,
	&__line,
	&__pill {
		background: linear-gradient(
			90deg,
			var(--color-background-hover) 25%,
			var(--color-background-dark) 37%,
			var(--color-background-hover) 63%
		);
		background-size: 400% 100%;
		animation: skeleton-shimmer 1.4s ease infinite;
	}
}

@keyframes skeleton-shimmer {
	0% { background-position: 100% 50%; }
	100% { background-position: 0 50%; }
}

@media (prefers-reduced-motion: reduce) {
	.skeleton__avatar,
	.skeleton__line,
	.skeleton__pill {
		animation: none;
	}
}
</style>
