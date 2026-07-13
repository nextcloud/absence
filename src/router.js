/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createRouter, createWebHashHistory } from 'vue-router'
import MyLeave from './views/MyLeave.vue'
import Approvals from './views/Approvals.vue'
import Team from './views/Team.vue'
import HrBalances from './views/hr/HrBalances.vue'
import HrStatistics from './views/hr/HrStatistics.vue'
import HrWhosOff from './views/hr/HrWhosOff.vue'
import HrExports from './views/hr/HrExports.vue'

const routes = [
	{ path: '/', redirect: '/my' },
	{ path: '/my', name: 'my', component: MyLeave },
	{ path: '/approvals', name: 'approvals', component: Approvals },
	{ path: '/team', name: 'team', component: Team },
	{ path: '/hr/balances', name: 'hr-balances', component: HrBalances },
	{ path: '/hr/statistics', name: 'hr-statistics', component: HrStatistics },
	{ path: '/hr/whos-off', name: 'hr-whos-off', component: HrWhosOff },
	{ path: '/hr/exports', name: 'hr-exports', component: HrExports },
	// Deep link from notifications/activity: open My leave with the request selected.
	{ path: '/requests/:id', name: 'request', component: MyLeave, props: true },
]

export default createRouter({
	history: createWebHashHistory(),
	routes,
})
