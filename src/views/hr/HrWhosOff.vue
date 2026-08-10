<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page">
		<header class="page__header">
			<h2 class="page__title">
				{{ t('absence', 'Who\'s off') }}
			</h2>
		</header>
		<p class="hint">
			{{ t('absence', 'Select an absence to see its details, or to correct or cancel it.') }}
		</p>
		<TeamTimeline
			scope="company"
			:selectable="true"
			@select="store.select($event)" />
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import TeamTimeline from '../../components/TeamTimeline.vue'
import { store } from '../../store.js'

export default {
	name: 'HrWhosOff',
	components: { TeamTimeline },
	setup() {
		// Expose the module-level reactive store to the template (Options API).
		return { store }
	},

	methods: { t },
}
</script>

<style scoped lang="scss">
.hint {
	margin: -12px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}
</style>
