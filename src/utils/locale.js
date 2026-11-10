/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getCanonicalLocale } from '@nextcloud/l10n'

/**
 * The locale picked in the Nextcloud personal settings, as a BCP 47 tag for
 * `Intl` and `toLocale*String()`, so dates and numbers follow the account and
 * not the browser.
 *
 * @return {string}
 */
export function locale() {
	return getCanonicalLocale()
}

/**
 * A number with at most one decimal, e.g. a count of days.
 *
 * @param {number|string} value the number to format
 * @return {string}
 */
export function formatNumber(value) {
	return Number(value).toLocaleString(locale(), { maximumFractionDigits: 1 })
}
