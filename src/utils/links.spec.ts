/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { newTabUrl } from './links.ts'

describe('newTabUrl', () => {
	const origin = 'https://cloud.example.org'

	it('keeps a web link', () => {
		expect(newTabUrl('https://example.org/handbook', origin)).toBe('https://example.org/handbook')
	})

	it('makes a path on this server a full URL', () => {
		expect(newTabUrl('/apps/collectives/Handbook', origin)).toBe('https://cloud.example.org/apps/collectives/Handbook')
	})

	it('has no link while the setting is empty', () => {
		expect(newTabUrl('', origin)).toBeNull()
	})

	it.each(['https://[', 'https://%', 'http://:80'])('has no link for %s, which does not parse', (url) => {
		expect(newTabUrl(url, origin)).toBeNull()
	})
})
