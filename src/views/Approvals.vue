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
						:active="store.selectedId === r.id"
						@select="store.select($event)" />
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
						:active="store.selectedId === r.id"
						@select="store.select($event)" />
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

<script>
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import CheckAll from 'vue-material-design-icons/CheckAll.vue'
import RequestListItem from '../components/RequestListItem.vue'
import SkeletonList from '../components/SkeletonList.vue'
import api from '../api.js'
import { store } from '../store.js'

const ACTIONABLE = ['PENDING', 'ESCALATED', 'WITHDRAWAL_PENDING']

export default {
	name: 'Approvals',
	components: { NcButton, NcEmptyContent, CheckAll, AlertCircleOutline, RequestListItem, SkeletonList },
	setup() {
		// Expose the module-level reactive store to the template (Options API).
		return { store }
	},

	data() {
		return {
			loading: true,
			loadError: false,
			teamQueue: [],
			escalated: [],
		}
	},

	mounted() {
		this.reload()
		window.addEventListener('absence:refresh', this.reload)
	},

	beforeUnmount() {
		window.removeEventListener('absence:refresh', this.reload)
	},

	methods: {
		t,
		async reload() {
			this.loading = true
			this.loadError = false
			try {
				const reports = await api.listRequests({ scope: 'reports' })
				this.teamQueue = reports.filter((r) => ACTIONABLE.includes(r.status))
				if (store.session.isHr) {
					this.escalated = await api.listRequests({ scope: 'hr', status: 'ESCALATED' })
				}
			} catch {
				// Without this a failed load leaves both lists empty and the
				// "All caught up!" state renders — a manager with pending requests
				// would think there was nothing to do. Show the failure instead.
				this.loadError = true
				this.teamQueue = []
				this.escalated = []
				showError(t('absence', 'Could not load the approvals'))
			} finally {
				this.loading = false
			}
		},
	},
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
