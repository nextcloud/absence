/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'

/**
 * What the library does to text: placeholders are HTML-escaped unless `escape`
 * is false, and the result goes through DOMPurify unless `sanitize` is false.
 * DOMPurify leaves text without "<" alone and drops anything that looks like a
 * tag (it needs a DOM, which these tests do not have, so it is imitated here).
 *
 * @param text the text
 */
function escapeHtml(text: string): string {
	return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

/**
 * @param text the text
 */
function sanitize(text: string): string {
	return text.includes('<') ? text.replace(/<[^>]*>/g, '') : text
}

vi.mock('@nextcloud/l10n', () => ({
	n: (app: string, singular: string, plural: string, count: number, vars: Record<string, string | number>, options: { escape?: boolean, sanitize?: boolean } = {}) => {
		const text = (count === 1 ? singular : plural).replace(/{(\w+)}/g, (_, key: string) => options.escape === false ? String(vars[key]) : escapeHtml(String(vars[key])))
		return options.sanitize === false ? text : sanitize(text)
	},
}))

const { namesAndMore } = await import('./people.js')

describe('namesAndMore', () => {
	it('lists everyone when all names are known', () => {
		expect(namesAndMore({ count: 2, names: ['Lee Novak', 'Pat Moreau'] })).toBe('Lee Novak, Pat Moreau')
	})

	it('counts the people beyond the listed names', () => {
		expect(namesAndMore({ count: 14, names: ['Casey Lin', 'Dana Ortiz', 'Eli Ramos'] })).toBe('Casey Lin, Dana Ortiz, Eli Ramos and 11 more')
	})

	it('keeps names as typed: Vue renders the text, so nothing is stripped or escaped', () => {
		expect(namesAndMore({ count: 3, names: ['R&D Team', 'Ann <Lee>'] })).toBe('R&D Team, Ann <Lee> and 1 more')
	})
})
