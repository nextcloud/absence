<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent appName="absence">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationNew :text="t('absence', 'New request')" @click="openNewRequest">
					<template #icon>
						<Plus :size="20" />
					</template>
				</NcAppNavigationNew>

				<NcAppNavigationItem
					:name="t('absence', 'My leave')"
					:to="{ name: 'my' }">
					<template #icon>
						<CalendarAccountOutline :size="20" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem
					v-if="session.isManager || session.isHr"
					:name="t('absence', 'Approvals')"
					:to="{ name: 'approvals' }">
					<template #icon>
						<ClipboardCheck :size="20" />
					</template>
					<template v-if="pendingCount > 0" #counter>
						<NcCounterBubble :count="pendingCount" type="highlighted" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem
					:name="t('absence', 'Team')"
					:to="{ name: 'team' }">
					<template #icon>
						<AccountGroup :size="20" />
					</template>
				</NcAppNavigationItem>

				<template v-if="session.isHr">
					<NcAppNavigationCaption :name="t('absence', 'HR')" />
					<NcAppNavigationItem :name="t('absence', 'Record absence')" @click="openRecord">
						<template #icon>
							<ClipboardPlusOutline :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :name="t('absence', 'Balances')" :to="{ name: 'hr-balances' }">
						<template #icon>
							<ScaleBalance :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :name="t('absence', 'Statistics')" :to="{ name: 'hr-statistics' }">
						<template #icon>
							<ChartBar :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :name="t('absence', 'Who\'s off')" :to="{ name: 'hr-whos-off' }">
						<template #icon>
							<CalendarMonth :size="20" />
						</template>
						<template v-if="session.escalatedCount > 0" #counter>
							<NcCounterBubble :count="session.escalatedCount" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :name="t('absence', 'Exports')" :to="{ name: 'hr-exports' }">
						<template #icon>
							<Download :size="20" />
						</template>
					</NcAppNavigationItem>
				</template>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<router-view />
		</NcAppContent>

		<RequestSidebar
			v-if="store.selectedId"
			:key="store.selectedId"
			@close="store.select(null)"
			@edit="openEditRequest"
			@changed="onChanged" />

		<RequestDialog
			v-if="showDialog"
			:request="editRequest"
			:hrMode="recordMode"
			@close="closeDialog"
			@saved="onChanged" />
	</NcContent>
</template>

<script>
import { provide } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import CalendarAccountOutline from 'vue-material-design-icons/CalendarAccountOutline.vue'
import CalendarMonth from 'vue-material-design-icons/CalendarMonth.vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import ClipboardCheck from 'vue-material-design-icons/ClipboardCheck.vue'
import ClipboardPlusOutline from 'vue-material-design-icons/ClipboardPlusOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import RequestDialog from './components/RequestDialog.vue'
import RequestSidebar from './components/RequestSidebar.vue'
import { store } from './store.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationNew,
		NcAppNavigationItem,
		NcAppNavigationCaption,
		NcCounterBubble,
		RequestDialog,
		RequestSidebar,
		Plus,
		CalendarAccountOutline,
		ClipboardCheck,
		AccountGroup,
		ScaleBalance,
		ChartBar,
		CalendarMonth,
		Download,
		ClipboardPlusOutline,
	},

	setup() {
		// Let any descendant view open the create/edit dialog or select a request.
		provide('absence:openNew', () => window.dispatchEvent(new CustomEvent('absence:open-new')))
		provide('absence:openEdit', (r) => window.dispatchEvent(new CustomEvent('absence:open-edit', { detail: r })))
		return { store }
	},

	data() {
		return {
			showDialog: false,
			editRequest: null,
			recordMode: false,
		}
	},

	computed: {
		session() {
			return store.session
		},

		pendingCount() {
			return store.session.pendingApprovals || 0
		},
	},

	mounted() {
		window.addEventListener('absence:open-new', this.openNewRequest)
		window.addEventListener('absence:open-edit', this.onOpenEditEvent)
		// Deep link: /requests/:id opens the sidebar.
		if (this.$route.params.id) {
			store.select(Number(this.$route.params.id))
		}
	},

	beforeUnmount() {
		window.removeEventListener('absence:open-new', this.openNewRequest)
		window.removeEventListener('absence:open-edit', this.onOpenEditEvent)
	},

	methods: {
		openNewRequest() {
			this.editRequest = null
			this.recordMode = false
			this.showDialog = true
		},

		openRecord() {
			this.editRequest = null
			this.recordMode = true
			this.showDialog = true
		},

		onOpenEditEvent(e) {
			this.openEditRequest(e.detail)
		},

		openEditRequest(request) {
			this.editRequest = request
			this.recordMode = false
			this.showDialog = true
			store.select(null)
		},

		closeDialog() {
			this.showDialog = false
			this.editRequest = null
			this.recordMode = false
		},

		onChanged() {
			this.closeDialog()
			// Close the detail sidebar so it can't show a stale (pre-decision) state.
			store.select(null)
			window.dispatchEvent(new CustomEvent('absence:refresh'))
		},
	},
}
</script>

<style scoped lang="scss">
// Everything else is themed by @nextcloud/vue; nothing custom needed at the shell level.
</style>

<style lang="scss">
// Keep the page titles clear of the floating app-navigation toggle button,
// which overlays the top-left corner of the content area (applies to every view).
.app-absence .page__header {
	padding-inline-start: calc(var(--default-clickable-area, 44px) + var(--app-navigation-padding, 4px) * 2);
}
</style>
