/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { translate, translatePlural } from '@nextcloud/l10n'

// Registered as globalProperties by main.ts (t and n) and personal-settings.ts (t only).
declare module 'vue' {
	interface ComponentCustomProperties {
		t: typeof translate
		n: typeof translatePlural
	}
}
