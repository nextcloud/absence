/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Black or white, whichever is easier to read on the given background
 * (WCAG relative luminance). Leave type colours are hex values chosen by HR.
 *
 * @param {string} background a hex colour, `#rgb` or `#rrggbb`
 * @return {'#000'|'#fff'} white when the colour cannot be read
 */
export function readableTextColor(background) {
	const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(background))?.[1]
	if (!hex) {
		return '#fff'
	}
	const full = hex.length === 3 ? [...hex].map((digit) => digit + digit).join('') : hex
	const [r, g, b] = [0, 2, 4]
		.map((at) => parseInt(full.slice(at, at + 2), 16) / 255)
		.map((channel) => (channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4))
	const luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b
	// Above this luminance black has the higher contrast ratio of the two.
	return luminance > 0.179 ? '#000' : '#fff'
}
