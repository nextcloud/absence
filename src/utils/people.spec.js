/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	n: (app, singular, plural, count, vars) => (count === 1 ? singular : plural).replace(/{(\w+)}/g, (_, key) => vars[key]),
}))

const { namesAndMore } = await import('./people.js')

describe('namesAndMore', () => {
	it('lists everyone when all names are known', () => {
		expect(namesAndMore({ count: 2, names: ['Lee Novak', 'Pat Moreau'] })).toBe('Lee Novak, Pat Moreau')
	})

	it('counts the people beyond the listed names', () => {
		expect(namesAndMore({ count: 14, names: ['Casey Lin', 'Dana Ortiz', 'Eli Ramos'] })).toBe('Casey Lin, Dana Ortiz, Eli Ramos and 11 more')
	})
})
