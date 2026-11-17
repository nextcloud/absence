/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** The sections of the settings page, in page order. */
export const SETTINGS_SECTIONS = ['who', 'vacation', 'approvals', 'calendar', 'holidays', 'handbooks', 'leave-types'] as const

export type SettingsSection = typeof SETTINGS_SECTIONS[number]

/**
 * The section a `section` query value names, null when it names none.
 *
 * @param value the query value: a string, or a list when repeated
 */
export function sectionFromQuery(value: unknown): SettingsSection | null {
	return SETTINGS_SECTIONS.find((section) => section === value) ?? null
}
