import { translate as t } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createApp } from 'vue'
import PersonalSettings from './views/settings/PersonalSettings.vue'

const app = createApp(PersonalSettings)
app.config.globalProperties.t = t
app.mount('#absence-personal-settings')
