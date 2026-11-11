/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getCanonicalLocale } from '@nextcloud/l10n'

/**
 * The locale picked in the Nextcloud personal settings, as a BCP 47 tag for
 * `Intl` and `toLocale*String()`, so dates and numbers follow the account and
 * not the browser.
 */
export function locale(): string {
	return getCanonicalLocale()
}

/**
 * A number with at most one decimal, e.g. a count of days.
 *
 * @param value the number to format
 */
export function formatNumber(value: number | string): string {
	return Number(value).toLocaleString(locale(), { maximumFractionDigits: 1 })
}
