/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * What every endpoint answers with on failure (4xx and 5xx), from
 * ApiControllerTrait::handle(): a message that is safe to show.
 */
export interface ApiError {
	message: string
}

/**
 * A PHP array keyed by strings. An empty one is encoded as `[]`, not `{}`, so
 * narrow with Array.isArray() before iterating keys.
 */
export type JsonMap<T> = Record<string, T> | []

/** A person by uid, with the name to show. The name falls back to the uid for a deleted account. */
export interface UserRef {
	uid: string
	displayName: string
}

/** A Nextcloud group: GET /api/groups (HR only) and the settings page. */
export interface Group {
	id: string
	displayName: string
}

/** Answer of an endpoint that returns nothing: PHP encodes its empty array as `[]`. */
export type EmptyResponse = []
