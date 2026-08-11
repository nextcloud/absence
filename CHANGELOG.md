<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## 1.0.12 – 2026-08-10

### Added
- Leave requests can be **approved straight from the notification**. The four
  notifications that ask for a decision — a new request, an escalation to HR, the
  overdue reminder and a withdrawal — now carry Approve, Decline and Review
  buttons; approving is a single click and never opens the app. Declining is
  deliberately not a one-click verdict, because a decline requires a reason: its
  button opens the request with the reason box already unfolded. A withdrawal
  asks the opposite question, so its buttons read *Approve withdrawal* and *Keep
  leave*. The overdue reminder previously carried no buttons at all, which is
  exactly the notification where an answer is most overdue
- The request dialog names the **colleagues already off** during the dates being
  picked, and warns when booking would take the team to the concurrent-absence
  limit. Managers have always seen this when reviewing; now the person choosing
  the dates sees it while they can still cheaply choose others. It never blocks
  the request, and it is only shown for your own leave — the overlap query
  answers for your team, so HR recording an absence for somebody else would
  otherwise be shown the wrong names
- The *next break* hero counts down **second by second over the final 48 hours**
  instead of rounding to "1 day to go", which is a poor description of an
  afternoon. The empty-state palm illustration now **follows the seasons** —
  blossom, full sun, falling leaves, snow — flipped for the southern hemisphere
  from the country already chosen for public holidays. All motion still stops
  under `prefers-reduced-motion`

### Fixed
- *My leave* left open overnight no longer freezes on yesterday: the day count
  and the reported year were captured once at render, so a tab open across
  midnight — or New Year — kept showing the previous day's numbers

## 1.0.11 – 2026-08-10

### Added
- **Expected notice period** (admin setting, default 14 calendar days, `0`
  disables). A request whose leave starts sooner is flagged as short notice to
  the line manager and to HR — on the request's Details tab, in the notification
  and in the subject line of the email asking for a decision, including the
  escalation and the pending reminder, by which point the notice given has shrunk
  further. It informs a decision and blocks nothing. Calendar days, not working
  days: "two weeks' notice" is a fortnight on the wall calendar. Never shown for
  leave with no approval step — nobody can give notice of falling ill

### Fixed
- Day boundaries are no longer computed in UTC. Nextcloud pins PHP's timezone to
  UTC for the whole request, so "today" answered in UTC wherever you are: at
  09:00 on 2 January in Auckland an employee booking leave for that day was told
  it was "entirely in the past", and Berlin had the same fault for the last hour
  of every day. Dates an employee is judged against now resolve in **their own**
  timezone, while company-wide policy and background jobs — short-notice
  measurement, carry-over expiry, the year rollover — use the **server's**, so
  one request gets the same answer for the employee, the manager and HR. Stored
  timestamps stay UTC, which is what a timestamp means

## 1.0.10 – 2026-08-10

### Fixed
- The bottom row of the *Who's off* / *Team* timeline is no longer clipped by the
  horizontal scrollbar. Overlay scrollbars (the macOS default) are painted over
  the content instead of taking space of their own, and the leave pills sat only
  8px clear of the track's bottom edge, so the last row's pill — and its
  selection ring — was sliced in half

## 1.0.9 – 2026-08-10

### Fixed
- Selecting a leave request no longer navigates away. The list row's underlying
  anchor fell through to `#`, which this app's hash routing resolves to *My
  leave* — so opening somebody's absence threw you back to your own overview
  instead of showing their record. Affected the HR absence list and Approvals

### Changed
- Requests now identify people by name instead of user id: the absence list, and
  the detail sidebar's Employee row (with avatar). Viewing somebody else's leave
  names them under the sidebar title, so it is clear whose record is open

## 1.0.8 – 2026-08-10

### Changed
- Guest accounts are no longer treated as employees. They have no entitlement
  and take no leave, so they no longer appear in balances, statistics, the
  sick-leave overview, exports, the who's-off calendar, the HR absence list or
  any employee picker, and they can no longer be nominated as a replacement or
  resolved as a line manager. Leave cannot be recorded for a guest, not even by
  HR. Instances without the Guests app are unaffected

## 1.0.7 – 2026-08-10

### Added
- **Absences** — a new HR view listing every recorded absence, filterable by
  employee, leave type, status and year. Selecting one opens the usual detail
  sidebar, so HR can now correct or cancel any vacation or sick day, not just
  record new ones. HR could always do this through the API; there was simply no
  screen that made an individual record reachable
- Absences in HR's *Who's off* timeline can be selected to open their details
- The *Sick leave* overview drills down: selecting an employee opens their
  individual sick days for that year

### Fixed
- HR editing an HR-recorded absence (e.g. sick leave) can now pick that type in
  the leave-type picker; previously the preselected type was missing from the
  list and could not be chosen again once changed

## 1.0.6 – 2026-07-19

### Fixed
- The nominated replacement is now notified that they no longer need to cover
  when approved leave is withdrawn or cancelled during withdrawal
- Only one edit of an approved request can be in flight at a time — previously
  two parallel edits could both be approved and overlap, double-counting the
  balance
- Declining a withdrawal now sends a dedicated "withdrawal declined"
  notification instead of a misleading "your leave was approved"
- Stale "needs a decision" notifications are dismissed once a request is
  approved or rejected
- HR members can edit and cancel their own HR-recorded leave (e.g. sick days)
- Escalation and reminder windows now count working days (Mon–Fri) instead of
  calendar days
- CSV exports no longer corrupt negative balance adjustments
- Deleting a user now also removes their events from the shared team calendar
  and detaches them as replacement
- Creating a leave type with a duplicate key returns a clean validation error
- The balance preview in the request dialog uses the year the leave starts in

## 1.0.5 – 2026-07-18

### Changed
- Admin settings migrated to declarative settings
- Security hardening across the app

## 1.0.4 – 2026-07-13

Initial public release:

- Leave requests with manager approval, HR escalation and override
- Full balance tracking with entitlements, carry-over and manual adjustments
- HR-recorded leave types (sick leave is recorded by HR, not self-requested)
- Mandatory replacement colleague for annual, unpaid and special leave
- Manually entered, manager-verified working-day counts with a client-side
  prefill from availability and public-holiday data
- Team coverage view and conflict warnings
- Approved leave synced to Nextcloud Calendar (personal + shared team calendar)
- Notifications, email, activity stream and an always-on audit log
- HR statistics, CSV export and a role-aware dashboard widget
