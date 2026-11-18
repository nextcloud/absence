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
	t: (app: string, text: string) => text,
}))

const { namesAndMore, namesOrNobody, replacementSuggestions } = await import('./people.js')

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

describe('namesOrNobody', () => {
	it('lists the names in their order', () => {
		expect(namesOrNobody([{ uid: 'pat', displayName: 'Pat Moreau' }, { uid: 'lee', displayName: 'Lee Novak' }])).toBe('Pat Moreau, Lee Novak')
	})

	it('says nobody for an empty list', () => {
		expect(namesOrNobody([])).toBe('nobody')
	})
})

describe('replacementSuggestions', () => {
	const alice = { uid: 'alice', displayName: 'Alice Martin' }
	const bob = { uid: 'bob', displayName: 'Bob Keller' }
	const charlie = { uid: 'charlie', displayName: 'Charlie Dubois' }
	const dave = { uid: 'dave', displayName: 'Dave Novak' }
	const eve = { uid: 'eve', displayName: 'Eve Laurent' }
	const uids = (list: { uid: string }[]) => list.map((person) => person.uid)

	it('offers the colleagues chosen before first, then the teammates by name', () => {
		expect(uids(replacementSuggestions({ past: [dave, bob], teammates: [eve, charlie], selected: null, subjectUid: 'alice' })))
			.toEqual(['dave', 'bob', 'charlie', 'eve'])
	})

	it('lists a teammate chosen before only once, where it was chosen', () => {
		expect(uids(replacementSuggestions({ past: [eve], teammates: [eve, charlie, bob], selected: null, subjectUid: 'alice' })))
			.toEqual(['eve', 'bob', 'charlie'])
	})

	it('never offers the person whose absence it is', () => {
		expect(uids(replacementSuggestions({ past: [alice], teammates: [alice, bob], selected: null, subjectUid: 'alice' })))
			.toEqual(['bob'])
	})

	it('keeps the current choice pickable when nobody else lists it', () => {
		expect(uids(replacementSuggestions({ past: [], teammates: [bob], selected: dave, subjectUid: 'alice' })))
			.toEqual(['dave', 'bob'])
		expect(uids(replacementSuggestions({ past: [], teammates: [bob], selected: bob, subjectUid: 'alice' })))
			.toEqual(['bob'])
	})
})
