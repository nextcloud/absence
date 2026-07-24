import { translatePlural as n, translate as t } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createApp } from 'vue'
import App from './App.vue'
import router from './router.js'

const app = createApp(App)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.use(router)
app.mount('#absence-app')
