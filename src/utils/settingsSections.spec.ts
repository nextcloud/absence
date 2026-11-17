/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { sectionFromQuery } from './settingsSections.ts'

describe('sectionFromQuery', () => {
	it('reads a known section', () => {
		expect(sectionFromQuery('vacation')).toBe('vacation')
		expect(sectionFromQuery('leave-types')).toBe('leave-types')
	})

	it('ignores what is not a section', () => {
		expect(sectionFromQuery(undefined)).toBeNull()
		expect(sectionFromQuery('')).toBeNull()
		expect(sectionFromQuery('nope')).toBeNull()
		expect(sectionFromQuery('toString')).toBeNull()
		expect(sectionFromQuery(['vacation'])).toBeNull()
	})
})
