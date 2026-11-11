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

				<NcAppNavigationItem :name="t('absence', 'Public holidays')" :to="{ name: 'holidays' }">
					<template #icon>
						<PartyPopper :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					v-if="session.handbookUrl"
					:name="t('absence', 'Handbook')"
					:href="newTabUrl(session.handbookUrl)"
					:ariaDescription="t('absence', 'Opens in a new tab')">
					<template #icon>
						<BookOpenVariant :size="20" />
					</template>
					<template #counter>
						<OpenInNew :size="16" />
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
					<NcAppNavigationItem :name="t('absence', 'Employees')" :to="{ name: 'hr-employees' }">
						<template #icon>
							<CardAccountDetailsOutline :size="20" />
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
						<template v-if="escalatedCount > 0" #counter>
							<NcCounterBubble :count="escalatedCount" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						v-if="session.hrHandbookUrl"
						:name="t('absence', 'HR handbook')"
						:href="newTabUrl(session.hrHandbookUrl)"
						:ariaDescription="t('absence', 'Opens in a new tab')">
						<template #icon>
							<BookOpenPageVariant :size="20" />
						</template>
						<template #counter>
							<OpenInNew :size="16" />
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
				v-if="closed && route.name !== 'hr-settings'"
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
			v-if="requestsStore.selectedId"
			:key="requestsStore.selectedId"
			@close="requestsStore.select(null)"
			@edit="openEditRequest"
			@changed="onChanged" />

		<RequestDialog
			v-if="showDialog"
			:request="editRequest"
			:hrMode="recordMode"
			@close="closeDialog"
			@saved="onChanged" />

		<ConfettiBurst />
	</NcContent>
</template>

<script setup lang="ts">
import type { Component } from 'vue'
import type { LeaveRequest } from './types/index.ts'

import { t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, provide, ref } from 'vue'
import { useRoute } from 'vue-router'
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
import CardAccountDetailsOutline from 'vue-material-design-icons/CardAccountDetailsOutline.vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import ClipboardCheck from 'vue-material-design-icons/ClipboardCheck.vue'
import ClipboardPlusOutline from 'vue-material-design-icons/ClipboardPlusOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import LightbulbOnOutline from 'vue-material-design-icons/LightbulbOnOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import PartyPopper from 'vue-material-design-icons/PartyPopper.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Thermometer from 'vue-material-design-icons/Thermometer.vue'
import ConfettiBurst from './components/ConfettiBurst.vue'
import RequestDialog from './components/RequestDialog.vue'
import RequestSidebar from './components/RequestSidebar.vue'
import { useRequestsStore } from './stores/requests.ts'
import { useSessionStore } from './stores/session.ts'
import { openEditKey, openNewKey, openRecordKey } from './utils/injectionKeys.ts'

const requestsStore = useRequestsStore()
const sessionStore = useSessionStore()

/** What the page shows instead of the views while the app is closed to the user */
interface ClosedState {
	name: string
	description: string | undefined
	icon: Component
}

// Let any descendant view open the create/edit/record dialog or select a request.
provide(openNewKey, () => window.dispatchEvent(new CustomEvent('absence:open-new')))
provide(openRecordKey, () => window.dispatchEvent(new CustomEvent('absence:open-record')))
provide(openEditKey, (r) => window.dispatchEvent(new CustomEvent('absence:open-edit', { detail: r })))

const route = useRoute()

const showDialog = ref(false)
const editRequest = ref<LeaveRequest | null>(null)
const recordMode = ref(false)

const session = computed(() => sessionStore.session)

const canOpenSettings = computed(() => sessionStore.session.isAdmin || sessionStore.session.isHr)

/** Why the app is closed to this user, or null. HR keeps its pages outside the employees group. */
const closed = computed<ClosedState | null>(() => {
	if (!sessionStore.session.configured) {
		return {
			name: t('absence', 'Absence isn\'t set up yet'),
			// Only those who can fix it are told why
			description: canOpenSettings.value ? t('absence', 'Choose the HR and employees groups in the settings.') : undefined,

			icon: CogOutline,
		}
	}
	if (!sessionStore.session.isEmployee && !sessionStore.session.isHr) {
		return {
			name: t('absence', 'You are not in the employees group'),
			description: canOpenSettings.value
				? t('absence', 'Absence is for the members of the employees group. You can choose that group in the settings.')
				: t('absence', 'Absence is for the members of the employees group. Ask HR if you should be in it.'),

			icon: AccountOffOutline,
		}
	}
	return null
})

const pendingCount = computed(() => sessionStore.session.pendingApprovals || 0)

const escalatedCount = computed(() => sessionStore.session.escalatedCount ?? 0)

onMounted(() => {
	window.addEventListener('absence:open-new', openNewRequest)
	window.addEventListener('absence:open-record', openRecord)
	window.addEventListener('absence:open-edit', onOpenEditEvent)
})

onBeforeUnmount(() => {
	window.removeEventListener('absence:open-new', openNewRequest)
	window.removeEventListener('absence:open-record', openRecord)
	window.removeEventListener('absence:open-edit', onOpenEditEvent)
})

/**
 * NcAppNavigationItem only opens links with a scheme in a new tab,
 * so a path on this server becomes a full URL
 *
 * @param url a handbook link from the settings
 */
function newTabUrl(url: string): string {
	return new URL(url, window.location.origin).href
}

/**
 * Open the form for a new request
 */
function openNewRequest(): void {
	editRequest.value = null
	recordMode.value = false
	showDialog.value = true
}

/**
 * Open the form HR records an absence with
 */
function openRecord(): void {
	editRequest.value = null
	recordMode.value = true
	showDialog.value = true
}

/**
 * Open the form on the request a view asked to edit
 *
 * @param e the event carrying the request
 */
function onOpenEditEvent(e: WindowEventMap['absence:open-edit']): void {
	openEditRequest(e.detail)
}

/**
 * Open the form on an existing request
 *
 * @param request the request to edit
 */
function openEditRequest(request: LeaveRequest): void {
	editRequest.value = request
	recordMode.value = false
	showDialog.value = true
	requestsStore.select(null)
}

/**
 * Close the form and forget what it was opened for
 */
function closeDialog(): void {
	showDialog.value = false
	editRequest.value = null
	recordMode.value = false
}

/**
 * A request was saved or decided: every open list reloads
 */
function onChanged(): void {
	closeDialog()
	// Close the detail sidebar so it can't show a stale (pre-decision) state.
	requestsStore.select(null)
	window.dispatchEvent(new CustomEvent('absence:refresh'))
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
