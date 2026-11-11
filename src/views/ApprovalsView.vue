<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page page--narrow page--roomy">
		<header class="page__header">
			<h2 class="page__title">
				{{ t('absence', 'Approvals') }}
			</h2>
		</header>

		<SkeletonList v-if="loading" :rows="3" />

		<NcEmptyContent
			v-else-if="loadError"
			:name="t('absence', 'Could not load the approvals')"
			:description="t('absence', 'Something went wrong while fetching the requests waiting for you.')">
			<template #icon>
				<AlertCircleOutline :size="20" />
			</template>
			<template #action>
				<NcButton @click="reload">
					{{ t('absence', 'Try again') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else>
			<section v-if="teamQueue.length" class="group">
				<h3 class="group__title">
					{{ t('absence', 'Awaiting your decision') }}
				</h3>
				<TransitionGroup tag="ul" name="rli" class="list">
					<RequestListItem
						v-for="r in teamQueue"
						:key="r.id"
						:request="r"
						:showEmployee="true"
						:active="requestsStore.selectedId === r.id"
						@select="requestsStore.select($event)" />
				</TransitionGroup>
			</section>

			<section v-if="escalated.length" class="group">
				<h3 class="group__title">
					{{ t('absence', 'Escalated to HR') }} ⏫
				</h3>
				<TransitionGroup tag="ul" name="rli" class="list">
					<RequestListItem
						v-for="r in escalated"
						:key="r.id"
						:request="r"
						:showEmployee="true"
						:active="requestsStore.selectedId === r.id"
						@select="requestsStore.select($event)" />
				</TransitionGroup>
			</section>

			<NcEmptyContent
				v-if="!teamQueue.length && !escalated.length"
				:name="t('absence', 'All caught up!')"
				:description="t('absence', 'No requests waiting for a decision. ✨')">
				<template #icon>
					<CheckAll :size="20" />
				</template>
			</NcEmptyContent>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { LeaveRequest, RequestStatus } from '../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import CheckAll from 'vue-material-design-icons/CheckAll.vue'
import RequestListItem from '../components/RequestListItem.vue'
import SkeletonList from '../components/SkeletonList.vue'
import api from '../services/api.ts'
import { useRequestsStore } from '../stores/requests.ts'
import { useSessionStore } from '../stores/session.ts'

const requestsStore = useRequestsStore()
const sessionStore = useSessionStore()

const ACTIONABLE: RequestStatus[] = ['PENDING', 'ESCALATED', 'WITHDRAWAL_PENDING']

const loading = ref(true)
const loadError = ref(false)
const teamQueue = shallowRef<LeaveRequest[]>([])
const escalated = shallowRef<LeaveRequest[]>([])

onMounted(() => {
	reload()
	window.addEventListener('absence:refresh', reload)
})

onBeforeUnmount(() => {
	window.removeEventListener('absence:refresh', reload)
})

/**
 * Load the requests waiting for the viewer, and for HR the escalated ones
 */
async function reload(): Promise<void> {
	loading.value = true
	loadError.value = false
	try {
		const [team, hr] = await Promise.all([
			api.listRequests({ scope: 'reports', status: ACTIONABLE.join(',') }),
			// Empty for anyone else, so the list of someone who stopped being HR goes away
			sessionStore.session.isHr ? api.listRequests({ scope: 'hr', status: 'ESCALATED' }) : [],
		])
		teamQueue.value = team
		escalated.value = hr
	} catch {
		// Without this a failed load leaves both lists empty and the
		// "All caught up!" state renders — a manager with pending requests
		// would think there was nothing to do. Show the failure instead.
		loadError.value = true
		teamQueue.value = []
		escalated.value = []
		showError(t('absence', 'Could not load the approvals'))
	} finally {
		loading.value = false
	}
}
</script>

<style scoped lang="scss">

.group__title {
	margin: 0 0 12px;
	font-size: 1.1rem;
}

.list {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 0;
	margin: 0;
	list-style: none;
}

.rli-enter-active,
.rli-leave-active { transition: opacity 250ms ease, transform 250ms ease; }

.rli-enter-from { opacity: 0; transform: translateY(8px); }

.rli-leave-to { opacity: 0; transform: translateX(-12px); }

.rli-move { transition: transform 250ms ease; }

@media (prefers-reduced-motion: reduce) {
	.rli-enter-active,
	.rli-leave-active,
	.rli-move { transition: none; }
}
</style>
