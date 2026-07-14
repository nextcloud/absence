/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Self-check for the working-day prefill counter. Run: node tests/countWorkingDays.test.mjs
 */
import assert from 'node:assert/strict'
import { countWorkingDays, parseWeekdays } from '../src/utils/dates.js'

const monFri = parseWeekdays('1,2,3,4,5')

// Mon–Fri, no holidays: a full Mon–Sun week has 5 working days.
assert.equal(countWorkingDays('2026-03-02', '2026-03-08', monFri, null), 5)

// Single weekend day counts as 0.
assert.equal(countWorkingDays('2026-03-07', '2026-03-07', monFri, null), 0)

// Reversed / empty range → 0.
assert.equal(countWorkingDays('2026-03-08', '2026-03-02', monFri, null), 0)

// A public holiday inside the range is subtracted.
const holiday = (iso) => iso === '2026-03-03'
assert.equal(countWorkingDays('2026-03-02', '2026-03-06', monFri, holiday), 4)

// Custom working days (Mon,Wed,Fri = 1,3,5) over a full week → 3.
assert.equal(countWorkingDays('2026-03-02', '2026-03-08', parseWeekdays('1,3,5'), null), 3)

// Empty weekday set → 0.
assert.equal(countWorkingDays('2026-03-02', '2026-03-08', parseWeekdays(''), null), 0)

console.log('countWorkingDays: all assertions passed')
