/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import api from '../services/api.ts'

/**
 * The display name of an employee, from the people search. That search follows
 * the admin's user-enumeration settings, so a person it does not reveal stays
 * their user id.
 *
 * @param uid the user id
 */
export async function displayNameOf(uid: string): Promise<string> {
	try {
		return (await api.searchUsers(uid)).find((person) => person.uid === uid)?.displayName ?? uid
	} catch {
		return uid
	}
}
