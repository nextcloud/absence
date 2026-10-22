<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - A check that flashes next to a settings section once one of its fields is
  - saved. The live region stays mounted so screen readers announce each save.
-->
<template>
	<span class="saved-check" role="status">
		<span v-if="savedAt" :key="savedAt" class="saved-check__icon">
			<Check :size="20" />
			<span class="hidden-visually">{{ t('absence', 'Saved') }}</span>
		</span>
	</span>
</template>

<script>
import { t } from '@nextcloud/l10n'
import Check from 'vue-material-design-icons/Check.vue'

export default {
	name: 'SavedCheck',
	components: { Check },
	props: {
		/** When the section was last saved, a new value flashes the check again */
		savedAt: {
			type: Number,
			default: 0,
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.saved-check {
	display: inline-flex;

	&__icon {
		display: inline-flex;
		color: var(--color-element-success);
		opacity: 0;
		animation: saved-check 2s ease-out;
	}
}

@keyframes saved-check {
	0% {
		opacity: 0;
		transform: scale(0.6);
	}

	15%,
	70% {
		opacity: 1;
		transform: scale(1);
	}

	100% {
		opacity: 0;
	}
}

@media (prefers-reduced-motion: reduce) {
	.saved-check__icon {
		animation-name: saved-check-fade;
	}

	@keyframes saved-check-fade {
		0%,
		70% {
			opacity: 1;
		}

		100% {
			opacity: 0;
		}
	}
}
</style>
