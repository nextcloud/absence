<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## 1.0.0-beta.4 – 2026-11-20

### Changed
* feat(insights): list the most sick days instead of the Bradford Factor by @skjnldsv in [#268](https://github.com/nextcloud/absence/pull/268)

### Fixed
* fix(notifications): no error on a carry-over reminder to a deleted account by @skjnldsv in [#269](https://github.com/nextcloud/absence/pull/269)

### Other
* ci: sync workflow templates by @skjnldsv in [#271](https://github.com/nextcloud/absence/pull/271)
* Bump php-cs-fixer/shim from 3.95.17 to 3.95.32
* Bump phpunit/phpunit from 11.5.56 to 11.5.58
* Bump sass from 1.108.1 to 1.108.2
* Bump vimeo/psalm from 7.0.2 to 7.12.0
* Bump vitest from 5.1.0 to 5.1.1
* Bump vue from 3.5.45 to 3.5.46
* Bump vue-router from 5.4.0 to 5.5.1

## 1.0.0-beta.3 – 2026-11-19

> [!IMPORTANT]
> Attachments are now encrypted with the instance secret. A background job
> encrypts the existing ones after the upgrade. Changing `secret` in
> `config.php` makes them unreadable.
>
> The shared team calendar is deleted on upgrade. Each employee and HR member
> now gets their own read-only "Team absences" calendar instead, as long as
> the team calendar is on.
>
> Other apps reading absences through the integration API only get the leave
> type when the visibility setting shows it, and no longer get the
> `confidential` flag.

### Added
* feat(notifications): setting to leave the text out of emails by @skjnldsv in [#257](https://github.com/nextcloud/absence/pull/257)
* feat(retention): delete old leave and former employees after set years by @skjnldsv in [#260](https://github.com/nextcloud/absence/pull/260)
* feat(gdpr): add Absence data to the personal data export by @skjnldsv in [#261](https://github.com/nextcloud/absence/pull/261)

### Changed
* fix(calendar): serve the team calendar in each viewer's calendar home by @skjnldsv in [#252](https://github.com/nextcloud/absence/pull/252)
* fix(calendar): delete the stored team calendar by @skjnldsv in [#253](https://github.com/nextcloud/absence/pull/253)
* fix(privacy): drop two note columns that no screen reads by @skjnldsv in [#255](https://github.com/nextcloud/absence/pull/255)
* fix(api)!: name leave types to other apps only under reveal by @skjnldsv in [#256](https://github.com/nextcloud/absence/pull/256)
* feat(calendar): use #ff6f59 as the default calendar colour by @skjnldsv in [#266](https://github.com/nextcloud/absence/pull/266)

### Fixed
* fix(ui): no scrollbar on the Settings entry of the navigation by @skjnldsv in [#244](https://github.com/nextcloud/absence/pull/244)
* fix(privacy): answer away only for leave in force by @skjnldsv in [#245](https://github.com/nextcloud/absence/pull/245)
* fix(attachments): 404 for attachments you may not see by @skjnldsv in [#246](https://github.com/nextcloud/absence/pull/246)
* fix(privacy): keep confidential content out of messages and history by @skjnldsv in [#247](https://github.com/nextcloud/absence/pull/247)
* fix(logging): mark free-text parameters sensitive by @skjnldsv in [#248](https://github.com/nextcloud/absence/pull/248)
* fix(gdpr): retry and complete the purge of deleted accounts by @skjnldsv in [#249](https://github.com/nextcloud/absence/pull/249)
* fix(coverage): count the employee's current team by @skjnldsv in [#250](https://github.com/nextcloud/absence/pull/250)
* fix(privacy): small logging and browser storage cleanups by @skjnldsv in [#251](https://github.com/nextcloud/absence/pull/251)
* fix(privacy): keep the free text of the request history for HR by @skjnldsv in [#254](https://github.com/nextcloud/absence/pull/254)
* fix(privacy): record every change of an employee's manager by @skjnldsv in [#258](https://github.com/nextcloud/absence/pull/258)
* fix(attachments): encrypt the stored files by @skjnldsv in [#259](https://github.com/nextcloud/absence/pull/259)
* fix(settings): leave-type switch also works with the team calendar off by @skjnldsv in [#263](https://github.com/nextcloud/absence/pull/263)
* fix(gdpr): take back manager-change notifications with the person's data by @skjnldsv in [#264](https://github.com/nextcloud/absence/pull/264)

### Other
* docs: retake the screenshots for 1.0.0-beta.2 by @skjnldsv in [#243](https://github.com/nextcloud/absence/pull/243)
* docs: privacy notes for admins and works councils by @skjnldsv in [#265](https://github.com/nextcloud/absence/pull/265)

## 1.0.0-beta.2 – 2026-11-17

> [!IMPORTANT]
> Leave is now taken and granted in full days only. Half days stored by an
> earlier version keep their value.
>
> Upgrading from 1.0.12 keeps every request, entitlement, comment and
> attachment. The working days each person set for themselves become a
> contract that starts on January 1 of the current year.

### Added
* feat(approvals): show what a decision needs on each queued request by @skjnldsv in [#210](https://github.com/nextcloud/absence/pull/210)
* feat(notifications): batch the comment e-mails by @skjnldsv in [#217](https://github.com/nextcloud/absence/pull/217)
* feat(replacement): offer teammates first and warn when they are away by @skjnldsv in [#220](https://github.com/nextcloud/absence/pull/220)
* feat(settings): links to the sections of the settings page by @skjnldsv in [#232](https://github.com/nextcloud/absence/pull/232)
* feat(team): show the employee's own manager in the Team view by @skjnldsv in [#233](https://github.com/nextcloud/absence/pull/233)

### Changed
* fix: take leave and set entitlements in full days only by @skjnldsv in [#219](https://github.com/nextcloud/absence/pull/219)
* fix(config): no HR group until an admin chooses one by @skjnldsv in [#222](https://github.com/nextcloud/absence/pull/222)
* fix(entitlements): nobody changes their own entitlement by @skjnldsv in [#223](https://github.com/nextcloud/absence/pull/223)

### Fixed
* fix(request): let the browser check the request form by @skjnldsv in [#193](https://github.com/nextcloud/absence/pull/193)
* fix(l10n): no em dashes in the app's strings by @skjnldsv in [#194](https://github.com/nextcloud/absence/pull/194)
* fix(settings): admins no longer see the HR group as locked by @skjnldsv in [#195](https://github.com/nextcloud/absence/pull/195)
* fix(permissions): keep the app closed outside the employees group on the server by @skjnldsv in [#200](https://github.com/nextcloud/absence/pull/200)
* fix(requests): keep confidential history and comments with HR by @skjnldsv in [#201](https://github.com/nextcloud/absence/pull/201)
* fix(requests): store and compare user ids in the account's own spelling by @skjnldsv in [#202](https://github.com/nextcloud/absence/pull/202)
* fix(profile): answer unknown accounts like forbidden ones and keep confidential leave off the profile by @skjnldsv in [#203](https://github.com/nextcloud/absence/pull/203)
* fix(settings): refuse links, numbers and expiry days that do not hold by @skjnldsv in [#204](https://github.com/nextcloud/absence/pull/204)
* fix(requests): keep an HR member's own leave with a second person by @skjnldsv in [#205](https://github.com/nextcloud/absence/pull/205)
* fix(requests): require the reason and refuse HR edits of closed requests by @skjnldsv in [#206](https://github.com/nextcloud/absence/pull/206)
* fix(attachments): cap the stored attachments of one employee at 200 MB by @skjnldsv in [#207](https://github.com/nextcloud/absence/pull/207)
* fix(rollover): leave carry-over imported with occ alone by @skjnldsv in [#208](https://github.com/nextcloud/absence/pull/208)
* fix(requests): keep the leave type and remove the replacement on edit by @skjnldsv in [#209](https://github.com/nextcloud/absence/pull/209)
* fix(ui): make list rows, page headers and page widths consistent by @skjnldsv in [#211](https://github.com/nextcloud/absence/pull/211)
* fix(ui): explain a refused profile, an empty sick chart and comment times by @skjnldsv in [#212](https://github.com/nextcloud/absence/pull/212)
* fix(l10n): stop stripping and escaping names that Vue renders as text by @skjnldsv in [#213](https://github.com/nextcloud/absence/pull/213)
* fix(timeline): show the whole month and label withheld leave by @skjnldsv in [#214](https://github.com/nextcloud/absence/pull/214)
* fix(balance): show one available figure everywhere by @skjnldsv in [#215](https://github.com/nextcloud/absence/pull/215)
* fix(charts): readable labels, one tile style and Material icons on HR pages by @skjnldsv in [#216](https://github.com/nextcloud/absence/pull/216)
* fix(request-dialog): reorder fields and keep the buttons in view by @skjnldsv in [#218](https://github.com/nextcloud/absence/pull/218)
* fix(balance): no default entitlement or carry-over outside the contract by @skjnldsv in [#221](https://github.com/nextcloud/absence/pull/221)
* fix(requests): require updatedAt to approve or reject by @skjnldsv in [#224](https://github.com/nextcloud/absence/pull/224)
* fix(requests): keep a leave type disabled since on an employee's edit by @skjnldsv in [#225](https://github.com/nextcloud/absence/pull/225)
* fix(ui): line up the figures of the year summary by @skjnldsv in [#226](https://github.com/nextcloud/absence/pull/226)
* fix(ui): one type scale for the whole app by @skjnldsv in [#227](https://github.com/nextcloud/absence/pull/227)
* fix(holidays): show employees their own holidays by @skjnldsv in [#228](https://github.com/nextcloud/absence/pull/228)
* fix(profile): a shorter own profile by @skjnldsv in [#229](https://github.com/nextcloud/absence/pull/229)
* fix(hr): employees list flags only real gaps by @skjnldsv in [#230](https://github.com/nextcloud/absence/pull/230)
* fix(hr): clearer Bradford bars, team names and durations on Insights by @skjnldsv in [#231](https://github.com/nextcloud/absence/pull/231)
* fix(ui): request rows wrap on narrow screens by @skjnldsv in [#234](https://github.com/nextcloud/absence/pull/234)
* fix(ui): regular weight for the values in the request panel by @skjnldsv in [#235](https://github.com/nextcloud/absence/pull/235)
* fix(api): refuse invalid dates and years on read endpoints by @skjnldsv in [#236](https://github.com/nextcloud/absence/pull/236)
* fix(requests): answer unknown and hidden requests alike by @skjnldsv in [#237](https://github.com/nextcloud/absence/pull/237)
* fix(export): quote every CSV field and neutralise formulas by @skjnldsv in [#238](https://github.com/nextcloud/absence/pull/238)
* fix(hr): no entitlement editor on HR's own row by @skjnldsv in [#239](https://github.com/nextcloud/absence/pull/239)
* fix(employees): JSON export follows the contract years by @skjnldsv in [#240](https://github.com/nextcloud/absence/pull/240)
* fix(migration): keep data entered on 1.0.12 through the upgrade by @skjnldsv in [#241](https://github.com/nextcloud/absence/pull/241)

### Other
* chore: stop committing the compiled assets by @skjnldsv in [#196](https://github.com/nextcloud/absence/pull/196)
* ci(dependabot): ignore TypeScript 7 until the tooling supports it by @skjnldsv in [#197](https://github.com/nextcloud/absence/pull/197)
* Bump sass from 1.106.1 to 1.108.1
* Bump vimeo/psalm from 6.20.3 to 7.0.2
* Bump vitest from 5.0.5 to 5.1.0
* Bump vue from 3.5.44 to 3.5.45

## 1.0.0-beta.1 – 2026-11-13

First public beta of Absence, a self-service leave-management app: employees
apply for leave, line managers approve, and HR gets the company-wide overview.
Employees are your Nextcloud users, HR is a Nextcloud group, and line managers
come from the `manager` account property your LDAP or Active Directory already
fills in.

### For employees
- Apply for annual, sick, unpaid or special leave, or any leave type HR
  defines, with a reason, a replacement colleague and attachments
- The working-day count is prefilled from your contract and the public
  holidays of your region, counts in half days, and stays editable
- A live balance preview before sending, and a warning (never a block) when
  the request goes past your balance
- The colleagues already off during the dates you pick, and a warning when
  booking would leave the team short
- A leave across New Year can be split at 31 December from the form
- **My leave**: a *next break* countdown, one balance ring per leave type with
  its full breakdown (base, carry-over, adjustment, used, pending, available),
  monthly charts and your request history
- Edit or cancel a pending request; withdrawing approved leave asks your
  manager first
- A profile page with your vacation, next leave, region code and this year's
  holidays, contract and sick days
- A warning before carried-over days expire
- A dashboard widget with your balance and upcoming leave, plus pending
  decisions for managers and escalations for HR

### For line managers
- Approve, decline or review straight from the notification, without opening
  the app. Declining always asks for a reason
- Each request shows who else on the team is off, a short-notice warning, the
  reason, a comment thread and the full history
- A Gantt-style **team timeline** of your reports, with weekends and each
  person's public holidays shaded, and a team balances table
- Requests you leave open are reminded, then escalated to HR. When you are on
  leave yourself they go to HR right away
- You hear about absences HR records for your reports, with the dates only

### For HR
- Every absence, filterable by employee, type, status and year, and
  correctable or cancellable. Cancelled records are kept for the audit trail
- **Record absences** such as sick leave directly, with no approval step
- **Confidential categories** (maternity leave, parental leave, child sick
  leave, medical work prohibition, doctor's note) that only HR sees. Everyone
  else, the employee included, sees a neutral "Absent". An HR-only disability
  flag on annual leave for the extra statutory entitlement
- **Attachments** such as doctor's notes, seen by HR and the employee only,
  never the manager
- An **Employees** page with everyone's region code, working days, contract,
  start date, manager and this year's days, flagging missing data. Contract
  data and vacation days can be edited per person or for everyone at once as
  JSON, with a review step
- Entitlements per person or in bulk for a group, each change with a note and
  a history. A pro rata suggestion for people who start mid-year or work part
  time
- **Public holidays** kept as a list per region code (`DE`, `DE-BY`), edited
  on a page or imported per year. HR can let everyone edit single holidays,
  with a full history, and remind people whose region code is missing
- **Statistics**: leave and sick days over time and by type, the busiest
  month, and who has the most vacation still to take
- **Insights**: approval times and escalations per manager, the Bradford
  Factor for sick leave, leave taken per team with a "not off in a while"
  watchlist, outstanding leave and carry-over at risk
- A sick-leave overview and a company-wide **who's off** timeline
- **CSV exports** of requests and balances for payroll, filtered by date range
  and group, with account id and e-mail
- **Import past leave** from a shared calendar or another tool as JSON, with a
  review before anything is written
- Links to your own handbooks, for example Collectives pages, in the app
  navigation

### For administrators
- All settings on a Settings page inside the app, open to HR and admins: HR
  and employees groups, default entitlement, escalation and reminder windows,
  carry-over policy, cap and expiry, notice period, coverage threshold,
  calendar options and leave types with their colours and rules
- Approved leave shown in a read-only Absence calendar of each employee, and
  written to a shared team calendar over CalDAV. The team calendar is shared
  read-only with the employees and HR groups, and shows a neutral "Absent"
  unless you choose otherwise
- Notifications, e-mail and Activity for every step
- Every action recorded in the admin audit log
- Background jobs for escalation, reminders, the year rollover with
  carry-over, and missing calendar events
- `occ` commands: `absence:import-entitlements`, `absence:import-employees`,
  `absence:export-employees`, `absence:import-holidays`,
  `absence:export-holidays`, `absence:import-leave` and `absence:rollover`
- An API for other apps: who is away, working days, contracts and public
  holidays through `IAbsenceProvider`, and `LeaveRequestChangedEvent` on every
  change. Confidential types stay hidden
- Permissions enforced on every endpoint, rate limiting, and GDPR handling
  when an account is deleted
- MySQL/MariaDB, PostgreSQL, SQLite and Oracle
