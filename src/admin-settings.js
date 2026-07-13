/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createApp } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import AdminSettings from './views/settings/AdminSettings.vue'

const app = createApp(AdminSettings)
app.config.globalProperties.t = t
app.mount('#absence-admin-settings')
