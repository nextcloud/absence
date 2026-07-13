<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page">
		<header class="page__header">
			<h2 class="page__title">{{ t('absence', 'Who\'s off') }}</h2>
		</header>
		<TeamTimeline scope="company" />
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import TeamTimeline from '../../components/TeamTimeline.vue'

export default {
	name: 'HrWhosOff',
	components: { TeamTimeline },
	methods: { t },
}
</script>

<style scoped lang="scss">
.page {
	max-width: 1100px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline, 4px) * 5);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 5);

	&__title {
		margin: 0;
		font-size: 1.6rem;
	}
}
</style>
