/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { RequestStatus } from '../types/index.ts'

import { t } from '@nextcloud/l10n'

/** How a request status shows */
export interface StatusMeta {
	label: string
	text: string
	tint: string
	icon: string
}

/**
 * Visual metadata for a request status (spec §15.4).
 * `text` uses Nextcloud's contrast-optimised *-text variables so labels stay
 * readable; `tint` is the base semantic colour used for the chip background.
 *
 * @param status the status, as the server names it
 */
export function statusMeta(status: RequestStatus | string): StatusMeta {
	switch (status) {
		case 'PENDING':
			return { label: t('absence', 'Pending'), text: 'var(--color-warning-text)', tint: 'var(--color-warning)', icon: '⏳' }
		case 'ESCALATED':
			return { label: t('absence', 'With HR'), text: 'var(--color-warning-text)', tint: 'var(--color-warning)', icon: '⏫' }
		case 'APPROVED':
			return { label: t('absence', 'Approved'), text: 'var(--color-success-text)', tint: 'var(--color-success)', icon: '✅' }
		case 'REJECTED':
			return { label: t('absence', 'Declined'), text: 'var(--color-error-text)', tint: 'var(--color-error)', icon: '✋' }
		case 'CANCELLED':
			return { label: t('absence', 'Cancelled'), text: 'var(--color-text-maxcontrast)', tint: 'var(--color-text-maxcontrast)', icon: '🚫' }
		case 'WITHDRAWAL_PENDING':
			return { label: t('absence', 'Withdrawal pending'), text: 'var(--color-warning-text)', tint: 'var(--color-warning)', icon: '↩️' }
		default:
			return { label: status, text: 'var(--color-main-text)', tint: 'var(--color-text-maxcontrast)', icon: '•' }
	}
}
