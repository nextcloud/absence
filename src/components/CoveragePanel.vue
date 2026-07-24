<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section">
		<NcNoteCard v-if="coverage.conflict" type="warning">
			{{ t('absence', 'Approving this would put {peak} team members off at once (limit {threshold}).', { peak: coverage.projectedPeak, threshold: coverage.threshold }) }}
		</NcNoteCard>
		<NcNoteCard v-else type="success">
			{{ t('absence', 'Coverage looks fine — at most {peak} away at once.', { peak: coverage.projectedPeak ?? coverage.maxConcurrent }) }}
		</NcNoteCard>

		<h4 class="section__title">
			{{ t('absence', 'Team members off during these dates') }}
		</h4>
		<ul v-if="coverage.events.length" class="overlap">
			<li v-for="ev in coverage.events" :key="ev.requestId" class="overlap__item">
				<NcAvatar
					:user="ev.employeeUid"
					:displayName="ev.displayName"
					:size="28"
					:showUserStatus="false" />
				<span class="overlap__name">{{ ev.displayName }}</span>
				<StatusChip :status="ev.status" />
			</li>
		</ul>
		<NcEmptyContent v-else :name="t('absence', 'Nobody else is off 🎉')">
			<template #icon>
				<AccountGroup :size="20" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import StatusChip from './StatusChip.vue'

export default {
	name: 'CoveragePanel',
	components: { NcAvatar, NcEmptyContent, NcNoteCard, AccountGroup, StatusChip },
	props: {
		coverage: { type: Object, required: true },
	},

	methods: { t },
}
</script>

<style scoped lang="scss">
.section {
	padding: calc(var(--default-grid-baseline, 4px) * 3);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);

	&__title {
		margin: 0;
		font-size: 0.95rem;
	}
}

.overlap {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;

	&__item {
		display: flex;
		align-items: center;
		gap: 10px;
	}

	&__name {
		flex: 1;
	}
}
</style>
