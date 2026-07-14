/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createAppConfig } from '@nextcloud/vite-config'

// Entry names are prefixed with the app id (from appinfo/info.xml) at build time,
// producing js/absence-main.mjs, js/absence-admin-settings.mjs, etc.
export default createAppConfig({
	main: 'src/main.js',
	'admin-settings': 'src/admin-settings.js',
	'personal-settings': 'src/personal-settings.js',
}, {
	// Inject component CSS from the JS entries (relative injection also covers
	// lazily-loaded chunk CSS), so a single Util::addScript() styles the whole app.
	inlineCSS: { relativeCSSInjection: true },
})
