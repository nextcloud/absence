/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Rough width of one character at the theme's small font size (13 px), in px
const CHAR_WIDTH = 8
// Room kept clear between two labels, in px
const LABEL_GAP = 8

/**
 * Every how many points an axis gets a label, so that no two labels overlap.
 *
 * @param labels the labels of the points
 * @param spacing the distance between two points, in px
 */
export function labelStep(labels: string[], spacing: number): number {
	if (labels.length <= 1 || spacing <= 0) {
		return 1
	}
	const longest = Math.max(...labels.map((label) => label.length))
	return Math.max(1, Math.ceil((longest * CHAR_WIDTH + LABEL_GAP) / spacing))
}
