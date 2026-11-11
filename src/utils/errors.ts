/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { ApiError } from '../types/index.ts'

import { isAxiosError } from '@nextcloud/axios'

/**
 * The message the server answered a failed request with, which is safe to show.
 *
 * @param error what the request threw
 * @return '' when the server sent none, or the request never got an answer
 */
export function serverMessage(error: unknown): string {
	if (!isAxiosError<Partial<ApiError> | undefined>(error)) {
		return ''
	}
	const message = error.response?.data?.message
	return typeof message === 'string' ? message : ''
}
