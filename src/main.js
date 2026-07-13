/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import router from './router.js'
import App from './App.vue'

const app = createApp(App)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.use(router)
app.mount('#absence-app')
