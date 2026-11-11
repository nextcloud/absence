/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const account = { locale: 'en-US' }
vi.mock('@nextcloud/l10n', () => ({ getCanonicalLocale: () => account.locale }))

const { formatNumber, locale } = await import('./locale.js')
const { formatDate } = await import('./dates.js')

describe('locale', () => {
	beforeEach(() => {
		account.locale = 'en-US'
	})

	it('is the locale of the Nextcloud account', () => {
		account.locale = 'de-DE'
		expect(locale()).toBe('de-DE')
	})

	it('formats numbers the way the account locale writes them', () => {
		expect(formatNumber(1234.5)).toBe('1,234.5')
		account.locale = 'de-DE'
		expect(formatNumber(1234.5)).toBe('1.234,5')
	})

	it('keeps one decimal at most', () => {
		expect(formatNumber(2.25)).toBe('2.3')
		expect(formatNumber('12')).toBe('12')
	})

	it('formats dates in the account locale, not the one of the browser', () => {
		account.locale = 'de-DE'
		expect(formatDate('2026-03-07')).toBe('7. März 2026')
		account.locale = 'en-US'
		expect(formatDate('2026-03-07')).toBe('Mar 7, 2026')
	})
})
