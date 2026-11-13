<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased] – 1.0.0-beta.1

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
