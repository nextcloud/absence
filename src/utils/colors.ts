/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Black or white, whichever is easier to read on the given background
 * (WCAG relative luminance). Leave type colours are hex values chosen by HR.
 *
 * @param background a hex colour, `#rgb` or `#rrggbb`
 * @return white when the colour cannot be read
 */
export function readableTextColor(background: string | null | undefined): '#000' | '#fff' {
	const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(background))?.[1]
	if (!hex) {
		return '#fff'
	}
	const full = hex.length === 3 ? [...hex].map((digit) => digit + digit).join('') : hex
	const channel = (at: number): number => {
		const value = parseInt(full.slice(at, at + 2), 16) / 255
		return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
	}
	const luminance = 0.2126 * channel(0) + 0.7152 * channel(2) + 0.0722 * channel(4)
	// Above this luminance black has the higher contrast ratio of the two.
	return luminance > 0.179 ? '#000' : '#fff'
}
