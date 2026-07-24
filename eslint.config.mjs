/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { recommendedJavascript } from '@nextcloud/eslint-config'

export default [
	...recommendedJavascript,

	{
		name: 'absence/disabled',
		rules: {
			// TODO: rename components to multi-word
			'vue/multi-word-component-names': 'off',
			// TODO: migrate to @nextcloud/logger
			'no-console': 'off',
		},
	},
]
