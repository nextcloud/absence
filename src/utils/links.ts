/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The full URL of a handbook link, or null when there is none or it does not
 * parse. NcAppNavigationItem only opens links with a scheme in a new tab, so a
 * path on this server becomes a full URL.
 *
 * @param url a handbook link from the settings, '' when there is none
 * @param origin the origin of this server
 */
export function newTabUrl(url: string, origin: string): string | null {
	if (url === '') {
		return null
	}
	try {
		return new URL(url, origin).href
	} catch {
		return null
	}
}
