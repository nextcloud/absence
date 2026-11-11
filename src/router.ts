/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { RouteRecordRaw } from 'vue-router'

import { createRouter, createWebHashHistory } from 'vue-router'
import ApprovalsView from './views/ApprovalsView.vue'
import HolidaysView from './views/HolidaysView.vue'
import HrAbsences from './views/hr/HrAbsences.vue'
import HrEmployees from './views/hr/HrEmployees.vue'
import HrExports from './views/hr/HrExports.vue'
import HrInsights from './views/hr/HrInsights.vue'
import HrSettings from './views/hr/HrSettings.vue'
import HrSickLeave from './views/hr/HrSickLeave.vue'
import HrStatistics from './views/hr/HrStatistics.vue'
import HrWhosOff from './views/hr/HrWhosOff.vue'
import MyLeave from './views/MyLeave.vue'
import ProfileView from './views/ProfileView.vue'
import TeamView from './views/TeamView.vue'
import { useSessionStore } from './stores/session.ts'
import { canOpenRoute } from './utils/access.ts'

declare module 'vue-router' {
	interface RouteMeta {
		/** For HR only */
		hr?: boolean
		/** Also open to admins, who set the app up before HR exists */
		admin?: boolean
	}
}

const routes: RouteRecordRaw[] = [
	{ path: '/', redirect: '/my' },
	{ path: '/my', name: 'my', component: MyLeave },
	{ path: '/approvals', name: 'approvals', component: ApprovalsView },
	{ path: '/team', name: 'team', component: TeamView },
	{ path: '/hr/absences', name: 'hr-absences', component: HrAbsences, meta: { hr: true } },
	{ path: '/hr/employees', name: 'hr-employees', component: HrEmployees, meta: { hr: true } },
	{ path: '/hr/balances', redirect: '/hr/employees' },
	{ path: '/hr/statistics', name: 'hr-statistics', component: HrStatistics, meta: { hr: true } },
	{ path: '/hr/insights', name: 'hr-insights', component: HrInsights, meta: { hr: true } },
	{ path: '/hr/sick-leave', name: 'hr-sick-leave', component: HrSickLeave, meta: { hr: true } },
	{ path: '/hr/whos-off', name: 'hr-whos-off', component: HrWhosOff, meta: { hr: true } },
	{ path: '/hr/exports', name: 'hr-exports', component: HrExports, meta: { hr: true } },
	{ path: '/hr/settings', name: 'hr-settings', component: HrSettings, meta: { hr: true, admin: true } },
	{ path: '/holidays', name: 'holidays', component: HolidaysView },
	{ path: '/profile', name: 'profile', component: ProfileView },
	{ path: '/profile/:uid', name: 'profile-of', component: ProfileView, props: true },
	// Deep link from notifications/activity: open My leave with the request selected.
	{ path: '/requests/:id', name: 'request', component: MyLeave, props: true },
	{ path: '/:pathMatch(.*)*', redirect: '/my' },
]

const router = createRouter({
	history: createWebHashHistory(),
	routes,
})

// The session store needs the app's Pinia, which main.ts installs before this router.
router.beforeEach((to) => canOpenRoute(to.meta, useSessionStore().session) || { name: 'my' })

export default router
