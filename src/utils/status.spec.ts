/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'
import { statusMeta } from './status.ts'

vi.mock('@nextcloud/l10n', () => ({ t: (app: string, text: string) => text }))

describe('statusMeta', () => {
	it('labels every known status', () => {
		for (const status of ['PENDING', 'ESCALATED', 'APPROVED', 'REJECTED', 'CANCELLED', 'WITHDRAWAL_PENDING']) {
			const meta = statusMeta(status)
			expect(meta.label).toBeTruthy()
			expect(meta.label).not.toBe(status)
			expect(meta.tint).toMatch(/^var\(--/)
		}
	})

	it('falls back to the raw status for an unknown one', () => {
		expect(statusMeta('SOMETHING_NEW').label).toBe('SOMETHING_NEW')
	})
})
