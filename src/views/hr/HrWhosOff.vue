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
		<p class="page__hint">
			{{ t('absence', 'Select an absence to see its details, or to correct or cancel it.') }}
		</p>
		<TeamTimeline
			scope="company"
			:selectable="true"
			@select="requestsStore.select($event)" />
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import TeamTimeline from '../../components/TeamTimeline.vue'
import { useRequestsStore } from '../../stores/requests.ts'

const requestsStore = useRequestsStore()
</script>
