/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { n } from '@nextcloud/l10n'

/**
 * The first few names of a group of people, then "and N more" for the rest.
 * Names are user-provided and rendered as text by Vue, so they are not escaped here.
 *
 * @param people total head count and the names to show
 * @param people.count the head count
 * @param people.names the names to show
 */
export function namesAndMore({ count, names }: { count: number, names: string[] }): string {
	const listed = names.join(', ')
	const rest = count - names.length
	if (rest <= 0) {
		return listed
	}
	return n('absence', '{names} and {count} more', '{names} and {count} more', rest, { names: listed, count: rest }, { escape: false })
}
