/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { recommended } from '@nextcloud/eslint-config'

export default [
	...recommended,

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
