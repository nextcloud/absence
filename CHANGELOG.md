# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
