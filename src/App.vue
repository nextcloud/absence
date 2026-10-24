<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent appName="absence">
		<NcAppNavigation>
			<template v-if="!closed" #list>
				<NcAppNavigationNew :text="t('absence', 'New request')" @click="openNewRequest">
					<template #icon>
						<Plus :size="20" />
					</template>
				</NcAppNavigationNew>

				<NcAppNavigationItem :name="t('absence', 'Profile')" :to="{ name: 'profile' }">
					<template #icon>
						<AccountCircleOutline :size="20" />
					</template>
				</NcAppNavigationItem>

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

				<NcAppNavigationItem
					:name="t('absence', 'Handbook')"
					:to="{ name: 'handbook' }">
					<template #icon>
						<BookOpenVariant :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('absence', 'Public holidays')" :to="{ name: 'holidays' }">
					<template #icon>
						<PartyPopper :size="20" />
					</template>
				</NcAppNavigationItem>

				<template v-if="session.isHr">
					<NcAppNavigationCaption :name="t('absence', 'HR')" />
					<NcAppNavigationItem :name="t('absence', 'Record absence')" @click="openRecord">
						<template #icon>
							<ClipboardPlusOutline :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :name="t('absence', 'Absences')" :to="{ name: 'hr-absences' }">
						<template #icon>
							<CalendarSearch :size="20" />
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
					<NcAppNavigationItem :name="t('absence', 'Insights')" :to="{ name: 'hr-insights' }">
						<template #icon>
							<LightbulbOnOutline :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :name="t('absence', 'Sick leave')" :to="{ name: 'hr-sick-leave' }">
						<template #icon>
							<Thermometer :size="20" />
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
					<NcAppNavigationItem :name="t('absence', 'HR handbook')" :to="{ name: 'hr-handbook' }">
						<template #icon>
							<BookOpenPageVariant :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :name="t('absence', 'Exports')" :to="{ name: 'hr-exports' }">
						<template #icon>
							<Download :size="20" />
						</template>
					</NcAppNavigationItem>
				</template>
			</template>
			<template v-if="session.isHr || session.isAdmin" #footer>
				<NcAppNavigationList class="app-navigation-entry__settings">
					<NcAppNavigationItem :name="t('absence', 'Settings')" :to="{ name: 'hr-settings' }">
						<template #icon>
							<CogOutline :size="20" />
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationList>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<NcEmptyContent
				v-if="closed && $route.name !== 'hr-settings'"
				class="closed"
				:name="closed.name"
				:description="closed.description">
				<template #icon>
					<component :is="closed.icon" :size="20" />
				</template>
				<template v-if="canOpenSettings" #action>
					<NcButton :to="{ name: 'hr-settings' }">
						{{ t('absence', 'Open the settings') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<router-view v-else />
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

		<Confetti />
	</NcContent>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { provide } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AccountCircleOutline from 'vue-material-design-icons/AccountCircleOutline.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountOffOutline from 'vue-material-design-icons/AccountOffOutline.vue'
import BookOpenPageVariant from 'vue-material-design-icons/BookOpenPageVariant.vue'
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariant.vue'
import CalendarAccountOutline from 'vue-material-design-icons/CalendarAccountOutline.vue'
import CalendarMonth from 'vue-material-design-icons/CalendarMonth.vue'
import CalendarSearch from 'vue-material-design-icons/CalendarSearch.vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import ClipboardCheck from 'vue-material-design-icons/ClipboardCheck.vue'
import ClipboardPlusOutline from 'vue-material-design-icons/ClipboardPlusOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import LightbulbOnOutline from 'vue-material-design-icons/LightbulbOnOutline.vue'
import PartyPopper from 'vue-material-design-icons/PartyPopper.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import Thermometer from 'vue-material-design-icons/Thermometer.vue'
import Confetti from './components/Confetti.vue'
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
		NcAppNavigationList,
		NcAppNavigationCaption,
		BookOpenPageVariant,
		BookOpenVariant,
		NcButton,
		NcCounterBubble,
		NcEmptyContent,
		Confetti,
		RequestDialog,
		RequestSidebar,
		Plus,
		CalendarAccountOutline,
		CalendarSearch,
		ClipboardCheck,
		AccountGroup,
		AccountCircleOutline,
		AccountOffOutline,
		ScaleBalance,
		ChartBar,
		CalendarMonth,
		Download,
		PartyPopper,
		ClipboardPlusOutline,
		Thermometer,
		LightbulbOnOutline,
		CogOutline,
	},

	setup() {
		// Let any descendant view open the create/edit/record dialog or select a request.
		provide('absence:openNew', () => window.dispatchEvent(new CustomEvent('absence:open-new')))
		provide('absence:openRecord', () => window.dispatchEvent(new CustomEvent('absence:open-record')))
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

		canOpenSettings() {
			return store.session.isAdmin || store.session.isHr
		},

		/** Why the app is closed to this user, or null. HR keeps its pages outside the employees group. */
		closed() {
			if (!store.session.configured) {
				return {
					name: t('absence', 'Absence isn\'t set up yet'),
					description: this.canOpenSettings
						? t('absence', 'Choose the employees group in the settings to open Absence to them.')
						: t('absence', 'An administrator or HR needs to finish setting it up.'),

					icon: CogOutline,
				}
			}
			if (!store.session.isEmployee && !store.session.isHr) {
				return {
					name: t('absence', 'You are not in the employees group'),
					description: this.canOpenSettings
						? t('absence', 'Absence is for the members of the employees group. You can choose that group in the settings.')
						: t('absence', 'Absence is for the members of the employees group. Ask HR if you should be in it.'),

					icon: AccountOffOutline,
				}
			}
			return null
		},

		pendingCount() {
			return store.session.pendingApprovals || 0
		},
	},

	mounted() {
		window.addEventListener('absence:open-new', this.openNewRequest)
		window.addEventListener('absence:open-record', this.openRecord)
		window.addEventListener('absence:open-edit', this.onOpenEditEvent)
		// Deep link: /requests/:id opens the sidebar.
		if (this.$route.params.id) {
			store.select(Number(this.$route.params.id))
		}
	},

	beforeUnmount() {
		window.removeEventListener('absence:open-new', this.openNewRequest)
		window.removeEventListener('absence:open-record', this.openRecord)
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
// Everything else is themed by @nextcloud/vue
.closed {
	height: 100%;
}
</style>

<style lang="scss">
// The page shell and table styles every view builds on. Global on purpose —
// see the file header.
@use './styles/page.scss';

// Keep the page titles clear of the floating app-navigation toggle button,
// which overlays the top-left corner of the content area (applies to every view).
.app-absence .page__header {
	padding-inline-start: calc(var(--default-clickable-area, 44px) + var(--app-navigation-padding, 4px) * 2);
}
</style>
