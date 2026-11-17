/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { UserRef } from '../types/index.ts'

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
	return n('absence', '{names} and {count} more', '{names} and {count} more', rest, { names: listed, count: rest }, { escape: false, sanitize: false })
}

/**
 * What the replacement picker offers before anything is typed (§5.1): the
 * colleagues chosen before first, then the teammates by name. Nobody twice,
 * never the person whose absence it is, and the current choice stays pickable.
 *
 * @param people the lists to merge
 * @param people.past colleagues chosen as replacement before, newest first
 * @param people.teammates the teammates of the person, in any order
 * @param people.selected the replacement picked now, null for none
 * @param people.subjectUid whose absence it is
 */
export function replacementSuggestions({ past, teammates, selected, subjectUid }: { past: UserRef[], teammates: UserRef[], selected: UserRef | null, subjectUid: string | null }): UserRef[] {
	const byName = [...teammates].sort((a, b) => a.displayName.localeCompare(b.displayName))
	const seen = new Set(subjectUid ? [subjectUid] : [])
	const list: UserRef[] = []
	for (const person of [...past, ...byName]) {
		if (!seen.has(person.uid)) {
			seen.add(person.uid)
			list.push(person)
		}
	}
	return selected && !seen.has(selected.uid) ? [selected, ...list] : list
}
