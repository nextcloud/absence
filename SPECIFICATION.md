# Absence — Vacation Approval Workflow for Nextcloud

**App ID:** `absence`
**Namespace:** `OCA\Absence`
**Target platform:** Nextcloud Server 34 (`min-version="34" max-version="36"`)
**License:** AGPL-3.0-or-later
**Category:** `organization` / `tools`

> This document is the implementation specification. It is intended to be handed to
> Claude Code (or a developer) as the single source of truth for building the app.
> It is deliberately prescriptive about data model, API, behavior, and the frontend
> design system (§15) — the UI must be built from `@nextcloud/vue` and feel native,
> elegant, and playful.

---

## 1. Purpose & Scope

Absence is a self-service leave-management app for a mid-size company (roughly
50–500 employees). It covers the full lifecycle of a leave request:

1. **Employees** apply for leave (vacation, sick, unpaid, or special leave).
2. **Line managers** approve or reject requests from their direct reports.
3. **HR** gets a company-wide overview, statistics, exports, and the ability to
   override/escalate, plus management of yearly entitlements and the public-holiday
   calendar.

The app is a standard Nextcloud app: PHP backend on the App Framework, Vue 3 +
`@nextcloud/vue` frontend, database migrations, background jobs, notifications,
activity entries, email, and CalDAV integration.

### 1.1 Out of scope (explicitly not built)

- Payroll integration (beyond CSV/Excel export).
- Time tracking / attendance / clock-in.
- Integration with the built-in `user_status` out-of-office / auto-responder
  feature (deliberately kept independent — see §12).
- Hour-level or half-day granularity (full days only — see §3.3).
- Shift planning.

---

## 2. Roles & Permissions

There are four effective roles. A single user may hold several simultaneously
(e.g. a manager is also an employee; an HR member may also manage a team).

| Role | How assigned | Capabilities |
|------|-------------|--------------|
| **Employee** | Every logged-in user | Create/edit/cancel own requests, view own balance, view own history, see team who's-off calendar. |
| **Line manager** | Derived from the LDAP `manager` attribute (see §2.1) — a user is a manager of everyone whose `manager` attribute points to them | Approve/reject/comment on direct reports' requests, view direct reports' calendars and balances, receive coverage-conflict warnings. |
| **HR** | Membership of a configurable Nextcloud group (default group id `hr`, set in admin settings) | Company-wide overview, statistics, exports, manage entitlements, manage public-holiday calendar, override any decision, act on escalated requests, edit/adjust any request and balance. |
| **App admin** | Nextcloud server admins | Configure app settings (§11): HR group, leave types, escalation window, default entitlements, CalDAV target. |

### 2.1 Determining the line manager (LDAP)

- The manager relationship is read from the user backend's `manager` attribute
  (LDAP `manager` DN, resolved to a Nextcloud user id).
- Implementation: read the manager via **`OCP\IUser::getManagerUids()`** (the
  canonical NC 34 API — a user may have several configured managers; the first valid
  one is used). Nextcloud populates this field from LDAP mappings where configured,
  and it can also be set directly on the account for non-LDAP setups.
  (Note: there is no `IAccountManager::PROPERTY_MANAGER` constant in NC 34 — the
  manager relationship lives on `IUser`, not in the account-properties list.)
- Resolution is cached per-request. The resolved manager user id is denormalized
  onto each leave request at submission time (`manager_uid`) so historical
  requests remain stable even if the org chart changes later.
- **No manager found:** the request is created with `manager_uid = NULL` and is
  routed directly to HR (treated as immediately escalated — see §5.4).

---

## 3. Core Concepts & Data Model

All tables are prefixed with the Nextcloud table prefix and namespaced `absence_`.
Use `OCP\Migration\IMigrationStep` / `ISchemaWrapper` migrations under
`lib/Migration/`. Entities use `OCP\AppFramework\Db\Entity` + `QBMapper`.

### 3.1 `absence_requests`

The central table: one row per leave request.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK, autoincrement | |
| `employee_uid` | string(64), indexed | Requesting user. |
| `manager_uid` | string(64), nullable, indexed | Denormalized at submission (§2.1). |
| `type_id` | int, FK → `absence_leave_types.id`, indexed | Leave type. |
| `start_date` | date | Inclusive. |
| `end_date` | date | Inclusive. |
| `working_days` | decimal(5,1) | **Entered manually** by the employee and verified by the manager (§7). Positive; stored as entered. |
| `status` | string(20), indexed | Enum, see §4. |
| `reason` | text, nullable | Optional employee note. |
| `replacement_uid` | string(64), nullable | The colleague nominated to cover (mandatory for types with `requires_replacement`, §5.1). |
| `attachment_note` | text, nullable | e.g. reference to a doctor's note (free text; file attachment optional, see §3.7). |
| `decided_by` | string(64), nullable | Manager or HR user id who made the last decision. |
| `decided_at` | datetime, nullable | |
| `decision_comment` | text, nullable | Rejection reason / approval note. |
| `escalated` | boolean, default false | True once auto-escalated to HR (§5.4). |
| `supersedes_id` | bigint, nullable | For the edit workflow: points to the request this one replaces (§5.3). |
| `calendar_event_uri` | string(255), nullable | Reference(s) to the CalDAV event(s) created on approval, for cleanup (§10). |
| `created_at` | datetime | |
| `updated_at` | datetime | |

Indexes: `(employee_uid, status)`, `(manager_uid, status)`, `(start_date, end_date)`, `(type_id)`.

### 3.2 `absence_leave_types`

Configurable leave types. Seeded with defaults on install; HR/admin can add/edit.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int, PK, autoincrement | |
| `key` | string(32), unique | Machine key, e.g. `annual`, `sick`, `unpaid`, `special`. |
| `label` | string(128) | Display name (translatable via l10n key where seeded). |
| `color` | string(7) | Hex color for chips, calendar events and ring segments. |
| `icon` | string(16) | Emoji shown alongside the label (🌴 annual, 🤒 sick, …), used across chips/calendar/widget (§15.4). |
| `counts_against_balance` | boolean | Annual/paid = true; sick/unpaid/special configurable. |
| `requires_approval` | boolean | When `false` → auto-approved & recorded on submit (§4.1). |
| `requires_note` | boolean | e.g. sick leave beyond N days requires a note. |
| `requires_replacement` | boolean, default false | When `true`, the employee **must** nominate a replacement colleague (§5.1). Annual/unpaid/special = true. |
| `employee_requestable` | boolean, default true | When `false`, employees **cannot self-request** this type; only HR records it, on an employee's behalf (§5.6). Sick leave = false. |
| `enabled` | boolean, default true | Soft-disable instead of delete. |
| `sort_order` | int | |

**Seeded defaults:**

| key | label | counts_against_balance | requires_approval | requires_note | requires_replacement | employee_requestable |
|-----|-------|------------------------|-------------------|---------------|----------------------|----------------------|
| `annual` | Annual leave | true | true | false | true | true |
| `sick` | Sick leave | false | false | false | false | **false** (HR-recorded) |
| `unpaid` | Unpaid leave | false | true | false | true | true |
| `special` | Special leave | false | true | false | true | true |

### 3.3 Granularity

**Full days only.** No half-days or hours. `working_days` is therefore an integer
in practice but stored as `decimal(5,1)` to leave room for a future half-day
feature without a migration.

### 3.4 `absence_entitlements`

Yearly leave entitlement (quota) per employee. Balance tracking is **full**:
entitlement + used + remaining + carry-over.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | |
| `employee_uid` | string(64), indexed | |
| `year` | int, indexed | Calendar year. |
| `type_id` | int, FK | Entitlement is per leave-type-that-counts (primarily `annual`). |
| `base_days` | decimal(5,1) | Annual allotment for that year. |
| `carry_over_days` | decimal(5,1), default 0 | Carried from previous year (§6.2). |
| `manual_adjustment` | decimal(5,1), default 0 | HR correction (+/−), with `adjustment_note`. |
| `adjustment_note` | text, nullable | |
| `created_at` / `updated_at` | datetime | |

Unique constraint: `(employee_uid, year, type_id)`.

**Balance formula** (computed, not stored):

```
entitlement = base_days + carry_over_days + manual_adjustment
used        = Σ working_days of requests in that year+type with status ∈ {APPROVED}
pending     = Σ working_days of requests in that year+type with status ∈ {PENDING, ESCALATED}
remaining   = entitlement − used
available   = entitlement − used − pending   (what the employee can still safely book)
```

A request that spans a year boundary is split for accounting: working days are
attributed to the year in which each day falls.

### 3.5 `absence_holidays` — **removed**

Public-holiday tracking was **removed** (§7): keeping an up-to-date holiday calendar per
region is impractical, so working days are entered manually instead. There is no
holidays feature, no region concept, and no `WorkingDayCalculator`. (The table may still
exist as an unused orphan on instances installed before the change; it is never read.)

### 3.6 `absence_comments` (optional but recommended)

Threaded comments on a request (employee ↔ manager ↔ HR discussion).

| Column | Type |
|--------|------|
| `id` | bigint PK |
| `request_id` | bigint FK, indexed |
| `author_uid` | string(64) |
| `body` | text |
| `created_at` | datetime |

### 3.7 `absence_request_events` (history timeline)

An immutable, append-only audit trail *per request*, surfaced in the request's
History tab (§15.1) so the employee, line manager and HR can all see exactly what
happened and when. One row is written for every meaningful transition.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | |
| `request_id` | bigint FK, indexed | The request this event belongs to. |
| `actor_uid` | string(64) | Who performed the action; the literal `system` for automated events (e.g. escalation). |
| `event_type` | string(32) | Machine key: `request_created`, `request_updated`, `request_edited_superseding`, `request_hr_edited`, `withdrawal_requested`, `request_cancelled`, `withdrawal_approved`, `request_approved`, `request_rejected`, `withdrawal_rejected`, `request_escalated`, `comment_added`. |
| `detail` | text, nullable | Human-readable extra (decision comment, new date range, comment body, "auto-approved", …). |
| `created_at` | datetime | |

Events are written by the same `audit()` path that emits the server-log entry (§11),
so history, server log and activity stay in sync from a single call site. History
writes are best-effort — a failure never blocks the workflow.

### 3.8 Attachments (optional, phase 2)

For doctor's notes: allow attaching a file reference stored in the user's Files.
Model as a nullable `attachment_file_id` on `absence_requests` pointing at a
Nextcloud file id. **Phase 2** — for phase 1 a free-text `attachment_note` suffices.

---

## 4. Request Status State Machine

```
                 ┌─────────────────────────────────────────────┐
                 │                                             │
  (create) ──▶ PENDING ──approve──▶ APPROVED ──withdraw req──▶ WITHDRAWAL_PENDING
                 │  │                   │                        │
       employee  │  │ auto-escalate     │ manager/HR approves    │
       cancels   │  │ (timeout §5.4)    │ withdrawal             ▼
                 ▼  ▼                   │                     CANCELLED
             CANCELLED  ESCALATED ──────┘ (balance restored)
                 ▲          │
                 │          ├─approve──▶ APPROVED
     manager/HR  │          └─reject───▶ REJECTED
     rejects ────┴──────────────────────▶ REJECTED
```

**Status enum values:** `PENDING`, `ESCALATED`, `APPROVED`, `REJECTED`,
`CANCELLED`, `WITHDRAWAL_PENDING`.

### 4.1 Auto-approval

If the leave type has `requires_approval = false`, a created request goes straight to
`APPROVED` (recorded, not routed). It still fires activity and appears in calendars and
stats, and can be cancelled/edited/adjusted afterwards. Note that sick leave combines
this with `employee_requestable = false`, so in practice it is **only ever created by
HR** on an employee's behalf (§5.6) — employees don't self-record it.

### 4.2 Balance effects

- `PENDING` / `ESCALATED` / `WITHDRAWAL_PENDING`: counts toward **pending** (reduces `available`, not `remaining`).
- `APPROVED`: counts toward **used** (reduces `remaining`).
- `REJECTED` / `CANCELLED`: no balance effect; if it was previously approved, the
  used days are released back.

---

## 5. Workflows

### 5.1 Employee applies for leave

1. Employee opens **New request**, picks a leave type, start & end date, optional reason.
2. Employee **enters the number of working days** manually (§7) — a required field. The
   dialog shows the impact on their balance (`available` before/after) from the entered
   number. Warn — do not block — if `available` would go negative (HR may still allow it;
   unpaid/special don't count).
3. **Replacement (mandatory for `requires_replacement` types).** For annual, unpaid and
   special leave the employee **must** nominate a **replacement** — a colleague who
   covers for them. The UI is a **user picker over the whole organisation**
   (`NcSelect` + core autocomplete), excluding the employee themselves. Submit is blocked
   until one is chosen. Sick leave (HR-recorded) needs none.
4. On submit, backend:
   - Validates dates (`start ≤ end`, not entirely in the past unless HR, not
     overlapping an existing non-terminal request for the same user — reject overlap
     with a clear error).
   - Enforces `requires_note`, **`requires_replacement`** (the `replacement_uid` must be
     present, a valid user, and not the employee), and that **`working_days` is a positive
     number** — all 422 otherwise. `working_days` is stored as entered, not recomputed.
   - Resolves `manager_uid` (§2.1).
   - Creates the request as `PENDING` (or `APPROVED` if auto-approve, §4.1; or
     `ESCALATED` if no manager, §2.1).
   - Fires notification + email + activity to the manager (or HR if escalated).

**Replacement notifications (§8).** When the request becomes **APPROVED**, the
replacement is informed with a **push notification** ("You are covering for {employee}
…"). When an approved request is later **cancelled/withdrawn**, the replacement is
notified they no longer need to cover. Editing a request re-validates the replacement;
HR can change it via the HR edit path.

### 5.2 Manager reviews

- Manager sees a queue of pending requests from direct reports.
- When opening a request, the manager sees a **coverage panel** (§8): who else on
  the team is off during the requested dates, and a conflict warning if the overlap
  exceeds the configured threshold.
- Manager **approves** (optional note) or **rejects** (comment required).
- Decision fires notification + email + activity to the employee. Balance updates per §4.2.

### 5.3 Employee edits / cancels (full edit workflow)

- **Pending request:** employee may edit fields or cancel outright. Editing a
  pending request updates it in place and re-notifies the manager.
- **Approved request — edit:** employee submits an edit; this creates a **new**
  request (`supersedes_id` → original) in `PENDING`, and the original stays
  `APPROVED` until the new one is approved. On approval of the superseding request,
  the original transitions to `CANCELLED` and balance is recomputed. On rejection,
  the superseding request becomes `REJECTED` and the original remains `APPROVED`.
- **Approved request — withdraw:** employee requests withdrawal → status
  `WITHDRAWAL_PENDING`; manager/HR must approve the withdrawal. On approval →
  `CANCELLED` (balance restored); on rejection → back to `APPROVED`.
- **Cancellation of any non-terminal request** restores pending/used balance.

### 5.4 Escalation (manager non-response)

- Background job (§9) scans `PENDING` requests older than the configured
  **escalation window** (admin setting, default 3 working days since creation with
  no decision).
- Such requests are marked `ESCALATED` (`escalated = true`), and HR is notified
  (notification + email + activity). HR can then approve/reject on the manager's behalf.
- Requests with no manager (§2.1) start life effectively escalated and are surfaced
  in the HR queue immediately.

### 5.5 HR override

- HR can approve/reject/cancel **any** request regardless of state, edit dates,
  adjust `working_days`, and edit any balance/entitlement (with an audit note).
- All HR overrides are recorded in activity and `decision_comment` / `adjustment_note`.

### 5.6 HR-recorded leave (e.g. sick leave)

Some leave types have **no approval workflow** and are **not self-requested** by
employees — sick leave is the canonical example (`employee_requestable = false`).

- **Employees never request it.** They notify HR through their normal channel
  (out of band); the type does not appear in the employee's "New request" type picker.
- **HR records it on the employee's behalf.** A `create` call may include an
  `employeeUid`; only HR may set it. The `PermissionService` rejects (403) any attempt
  by a non-HR user to record on behalf of someone else, or to create a
  non-`employee_requestable` type.
- **Booked directly to `APPROVED`** with no manager routing — non-requestable types,
  auto-approve types (`requires_approval = false`), and *any* HR-on-behalf creation are
  recorded straight to approved (`decided_by = the HR user`, `decided_at = now`).
- **Fully visible & on the calendar.** Because it is approved, it appears for the
  employee (My leave), their line manager (Team) and HR (overview), counts in stats, and
  is written to the shared/personal **CalDAV** calendar exactly like any approved leave
  (§10). Sick leave does not count against the balance (`counts_against_balance = false`).
- Recorded via a dedicated **"Record absence"** HR action in the UI (§14.2) with an
  employee search + date range + type; history/audit note the entry as "Recorded by HR".
- **Only HR can edit or cancel it.** For an HR-recorded type, `PermissionService::canModify`
  returns false for the employee (owner) — so the employee sees no Edit/Cancel controls
  and the API rejects such attempts; only HR may change it (§17).
- **No "Approved" label shown.** Since approval isn't a concept for HR-recorded leave,
  the UI hides the status chip and the approval **progress stepper** when such a request
  is `APPROVED` (a cancelled one still shows its status). The leave-type chip (e.g.
  "🤒 Sick leave") still identifies it.

---

## 6. Balances & Entitlements

### 6.1 Entitlement management (HR)

- HR sets `base_days` per employee per year per counting type via the HR area.
- Bulk actions: set a default entitlement for a whole group/all employees for a
  given year (admin default in settings, §11, used to seed).
- Manual adjustments (+/−) require an `adjustment_note`.

### 6.2 Carry-over (year rollover)

- A background job (or HR-triggered action) at year start computes carry-over into
  the new year's entitlement row.
- Carry-over policy is configurable (admin setting): `none`, `unlimited`, or
  `capped` at N days, with an optional **expiry date** (e.g. carried days expire
  end of Q1). Expired carry-over is zeroed by the rollover/expiry job.
- The rollover creates the next year's `absence_entitlements` rows using the default
  `base_days` + computed `carry_over_days`.

---

## 7. Working Days (manually entered)

There is **no automatic working-day calculation**. Keeping an accurate, always-current
public-holiday calendar for every region is impractical, so the app does not attempt it.

- The **employee enters the number of working days** the absence covers (excluding
  weekends and public holidays — their judgement), as a required numeric field on the
  request (`working_days`).
- The **line manager reviews and verifies** this number when approving. HR may correct it
  via the HR edit path (§5.5).
- The server validates only that it is a positive, sane number (`> 0`, `≤ 366`); it never
  recomputes it. `working_days` is stored as entered and is authoritative.
- **Accounting simplification:** because there is no day-by-day breakdown, a request's
  working days are attributed wholly to the **year (for balances, §3.4) and month (for
  trends, §13) in which it starts**. Year-boundary requests are rare; split them into two
  requests if precise per-year accounting is needed.

> Consequences of removing holidays: there is **no `WorkingDayCalculator`, no public
> holidays feature (`absence_holidays`), and no per-user/region setting** — the personal
> settings page and the admin "default region" option are gone.

---

## 8. Team Coverage & Conflict Warnings

- **Team calendar / who's-off:** managers see their direct reports; HR sees the
  whole company; every employee sees their own team (peers who share the same
  `manager_uid`). A month/timeline view rendered from approved + pending requests.
- **Conflict warning:** when a manager reviews a request, compute the maximum number
  of concurrently-absent team members on any day in the requested range. If it meets
  or exceeds a configurable threshold (admin setting **max concurrent absences per
  team**, default e.g. 2, or a percentage), show a prominent warning in the review
  panel. It is a warning, not a hard block.
- Provide an API endpoint to query overlaps for a date range + scope (team/company).

---

## 9. Background Jobs

Registered in `appinfo/info.xml` `<background-jobs>` and/or via `IJobList`, using
`TimedJob`:

1. **EscalationJob** (hourly): finds overdue `PENDING` requests and escalates (§5.4).
2. **ReminderJob** (daily): sends reminder notifications to managers with pending
   requests approaching the escalation window.
3. **YearRolloverJob** (daily, acts once per year / idempotent): computes carry-over
   and seeds next-year entitlements (§6.2); zeroes expired carry-over.
4. **CalendarSyncJob** (optional, if async sync chosen): reconciles approved leave
   with the CalDAV target calendar (§10).

All jobs must be idempotent and safe to run repeatedly.

---

## 10. Calendar Integration (CalDAV)

Approved leave is written into Nextcloud Calendar via CalDAV.

- Use the `dav`/`calendar` server APIs — `OCA\DAV\CalDAV\CalDavBackend` /
  `OCP\Calendar\ICalendarProvider` & `OCP\Calendar\IManager` for reading, and the
  CalDAV backend for writing events. Prefer the public `OCP\Calendar` interfaces
  where write support is available; otherwise write `VEVENT` objects to the target
  calendar via the DAV backend.
- **Two targets (both configurable, either can be disabled in admin settings):**
  1. **Personal:** an all-day `VEVENT` on the employee's own calendar (auto-created
     "Absence" calendar or a configured one) marked as busy/out-of-office
     (`X-NC-... busy` / `TRANSP: OPAQUE`).
  2. **Shared team "Absences" calendar:** a company/team calendar showing everyone's
     approved leave (all-day events titled with employee + leave type, colored by
     type). Auto-provisioned and shared with the relevant groups.
- **Lifecycle:** create the event on approval; delete/update it on
  cancellation/withdrawal/edit. Store the created event's URI/UID on the request
  (add nullable `calendar_event_uri` column to `absence_requests`) so it can be
  cleaned up.
- Sick-leave / private types: allow admin to configure whether the event title on
  the shared calendar reveals the type or shows a neutral "Absent".
- Sync may be synchronous (on decision) or via `CalendarSyncJob`; synchronous is
  acceptable for phase 1, with the job as a reconciler.

---

## 11. Notifications, Email, Activity & Server Log

All four channels are required.

- **Nextcloud notifications:** implement `OCP\Notification\INotifier`. Events:
  new request (→ manager), decision made (→ employee), escalation (→ HR),
  reminder (→ manager), withdrawal request (→ manager/HR), **replacement assigned**
  (→ replacement, on approval) and **replacement cancelled** (→ replacement, when
  approved leave is cancelled) — §5.1. These are pushed (a standard NC notification is
  delivered to push automatically). Provide actionable notifications (Approve/Reject
  buttons where feasible) linking into the app.
- **Email:** via `OCP\Mail\IMailer` with templated messages
  (`OCP\Mail\IEMailTemplate`) for each of the above events. Respect the user's
  configured email + language.
- **Activity:** implement `OCP\Activity\IProvider` / setting so all state changes
  appear in the Activity app feed, filterable to an "Absence" activity type. Include
  activity for HR overrides and balance adjustments.
- **Server-log audit trail (always-on):** every important action is written to
  `nextcloud.log` as a structured entry tagged `["app" => "absence"]` with a
  machine-readable `action` and full context (actor, request id, employee, type,
  dates, working days, status). Covered actions: the full request lifecycle (create,
  edit, superseding edit, HR edit, approve, reject, cancel, withdrawal
  request/approve/reject, escalate, comment), entitlement changes, bulk-set,
  carry-over rollover/expiry, leave-type and holiday changes, admin-config changes,
  and GDPR user-data purge.
  - These entries must appear **regardless of the instance log level**. Achieve this
    with Nextcloud's `log.condition.apps` mechanism: on install/update a repair step
    merges `absence` into `log.condition.apps` (never clobbering existing config),
    which makes Nextcloud force DEBUG capture for the app's tagged messages; a
    matching uninstall step removes it. Only messages tagged `app=absence` (the audit
    calls) are forced always-on — incidental diagnostic logs still follow the normal
    level.
  - The same call site also writes the per-request history event (§3.7), keeping the
    log, history and activity in sync.

---

## 12. Admin Settings

App admin settings page (`OCP\Settings\ISettings`, `type: 'admin'`, section under a
new "Absence" settings section or "Personal info"/"Administration"):

| Setting | Default | Notes |
|---------|---------|-------|
| HR group id | `hr` | Which NC group is HR (§2). |
| Default annual entitlement (days) | 25 | Seed for new entitlement rows. |
| Escalation window | 3 working days | For EscalationJob (§5.4). |
| Reminder lead time | 1 day before escalation | For ReminderJob. |
| Carry-over policy | `capped` | `none` / `unlimited` / `capped`. |
| Carry-over cap (days) | 5 | Used when `capped`. |
| Carry-over expiry | none / date (e.g. Mar 31) | §6.2. |
| Max concurrent team absences | 2 | Conflict threshold (§8). |
| CalDAV: write personal events | true | §10. |
| CalDAV: write shared team calendar | true | §10. |
| Shared calendar type-visibility | neutral | Reveal type vs "Absent" on shared cal. |
| Leave types | seeded (§3.2) | Add/edit/enable/disable, colors, flags. |

There is **no personal settings page** — the only per-user setting (holiday region) was
removed with the working-day calculator (§7). Notification preferences defer to the
global Nextcloud notification settings.

> **Note on out-of-office:** the app does **not** touch the built-in
> `user_status` out-of-office / auto-responder feature. Calendar busy state (§10) is
> the only presence signal it sets.

---

## 13. HR Overview, Statistics & Export

The HR area (visible only to HR-group members) provides:

1. **Per-employee balances table:** entitlement / used / pending / remaining /
   carry-over, per year and type. Filterable by group/department, searchable,
   sortable. Drill-down to an employee's request history.
2. **Company-wide trends (charts):** absence days over time (by month), by leave
   type, by department/group; headcount-on-leave heatmap. Use a lightweight charting
   approach consistent with Nextcloud (e.g. `vue-chartjs`/Chart.js already used
   elsewhere in the ecosystem — confirm a bundled option; otherwise a small SVG
   chart component). Follow the `dataviz` design guidance for palette/accessibility.
3. **Who's-off calendar (org-wide):** all absences, filterable by team/type, for
   planning.
4. **Export:** CSV and Excel (`.xlsx`) export of raw requests and of the balances
   report, with date-range and group filters, for payroll/external HR. CSV via
   native PHP; XLSX via a bundled library (e.g. PhpSpreadsheet) or a documented CSV
   fallback if a dependency is undesirable.

Managers get a scoped version (their reports only) of the balances table and who's-off calendar.

---

## 14. HTTP API (Controllers)

RESTful controllers under `lib/Controller/`, routes in `appinfo/routes.php`, all
guarded by the appropriate middleware/attribute-based access checks
(`#[NoAdminRequired]` for employee endpoints; explicit HR/manager checks in a shared
`PermissionService`). Use OCS or app routes consistently (app routes recommended for
the SPA). All list endpoints paginate and accept filters.

**Requests**
- `GET  /api/requests` — list (scoped by role: own / reports / all-for-HR; filters: status, type, date range, employee, group).
- `POST /api/requests` — create (§5.1).
- `GET  /api/requests/{id}` — detail (with comments, coverage summary).
- `PUT  /api/requests/{id}` — edit (§5.3; behavior depends on current status).
- `POST /api/requests/{id}/cancel` — cancel / request withdrawal.
- `POST /api/requests/{id}/approve` — manager/HR approve (optional comment).
- `POST /api/requests/{id}/reject` — manager/HR reject (comment required).
- `POST /api/requests/{id}/comments` — add comment.

**Balances & entitlements**
- `GET  /api/balance` — current user's balance (all years/types or filtered).
- `GET  /api/employees/{uid}/balance` — manager (reports) / HR only.
- `GET  /api/entitlements` / `PUT /api/entitlements/{id}` — HR manage.
- `POST /api/entitlements/bulk` — HR bulk set.

**Coverage & calendar**
- `GET  /api/coverage?from&to&scope=team|company` — overlaps + conflict count (§8).
- `GET  /api/calendar?from&to&scope` — events for the in-app calendar/timeline.

**Reference data**
- `GET  /api/leave-types`.
- HR/admin CRUD for leave types. (No holidays endpoints — the holidays/region
  feature was removed, §7.)

**HR reporting**
- `GET  /api/reports/balances` — balances report (filters).
- `GET  /api/reports/trends` — aggregated stats for charts.
- `GET  /api/export/requests.csv|.xlsx`, `GET /api/export/balances.csv|.xlsx`.

All write endpoints require CSRF protection (default AppFramework) and validate role
server-side. Never trust client-computed `working_days` or `manager_uid`.

---

## 15. Frontend (Vue 3 + @nextcloud/vue) — Design System & UX

Single-page app mounted from the app's main navigation entry, plus HR/admin settings
pages. Build with Vite (the tree already uses Vite — see `build/frontend`). Use
`@nextcloud/vue`, `@nextcloud/axios`, `@nextcloud/router`, `@nextcloud/l10n`,
`@nextcloud/dialogs`, and `@mdi/svg` / `vue-material-design-icons` for icons.

### 15.0 Design principles

The app must feel **native to Nextcloud, pretty, elegant, and playful** — not a
generic form-over-table CRUD tool.

- **Native first, always the design system.** Never hand-roll a control that
  `@nextcloud/vue` already provides. All layout, spacing, radius, elevation, and color
  come from **Nextcloud CSS variables** (`--color-*`, `--border-radius*`,
  `--default-grid-baseline` spacing scale, `--animation-*`). No hard-coded hex colors,
  no custom pixel spacing that ignores the grid baseline.
- **Elegant.** Generous whitespace, a calm neutral canvas (`--color-main-background`),
  content grouped on subtly elevated cards (`--color-background-hover`), one clear
  primary action per view. Density and typography follow Nextcloud defaults — no dense
  spreadsheets except where HR genuinely needs a data table.
- **Playful, tastefully.** Personality lives in small moments, never at the expense of
  clarity or accessibility:
  - Leave types carry a **color + emoji/MDI icon** (🌴 annual, 🤒 sick, 🕊️ special,
    …) used consistently across chips, calendar events, and cards.
  - **Friendly empty states** via `NcEmptyContent` with an illustrative icon and warm
    one-liner ("No requests yet — time to plan a break? 🌴").
  - **Micro-interactions**: gentle transitions on status changes — suppressed under
    `prefers-reduced-motion`. (Decisions themselves stay quiet: approving shows no
    toast or confetti; the updated status chip is the feedback.)
  - **Balance shown as delightful progress rings/bars**, not just numbers — a donut
    ring of used vs. remaining per type, animated on load.
  - Warm, human microcopy throughout (§15.5).
- **Accessible & adaptive (non-negotiable).** WCAG AA contrast in light *and* dark
  themes, full keyboard nav, visible focus rings, ARIA labels, respects
  `prefers-reduced-motion` and high-contrast themes. Playfulness decorates; it never
  carries meaning alone.
- **Responsive.** Works from mobile width up; navigation collapses per Nextcloud
  behavior; tables become card lists on narrow screens.

### 15.1 App shell & layout (standard three-region Nextcloud frame)

Use the canonical Nextcloud app scaffold so the sidebar, content, and detail sidebar
behave exactly like every other Nextcloud app:

```
NcContent(app-name="absence")
├── NcAppNavigation                         ← LEFT sidebar (primary navigation)
│   ├── NcAppNavigationNew ("＋ New request")   ← prominent primary CTA at top
│   ├── NcAppNavigationItem  My leave           (icon: mdiBeach)
│   ├── NcAppNavigationItem  Approvals  [badge]  (managers only; badge = pending count)
│   ├── NcAppNavigationItem  Team               (icon: mdiAccountGroup)
│   ├── NcAppNavigationCaption "HR"             (HR only)
│   ├── NcAppNavigationItem    Balances
│   ├── NcAppNavigationItem    Statistics
│   ├── NcAppNavigationItem    Who's off
│   └── NcAppNavigationItem    Exports          (no settings entry — §12: no personal settings)
├── NcAppContent                            ← CENTER (the active routed view)
│   └── router-view
└── NcAppSidebar                            ← RIGHT detail sidebar (request detail)
      └── NcAppSidebarTab(s): Details · Coverage · Comments · History
```

- **Left `NcAppNavigation`** is the required primary sidebar. Items are gated by role
  (§2): managers see *Approvals* with a live pending-count `NcCounterBubble`; HR sees
  the *HR* section (grouped under an `NcAppNavigationCaption`). Every item uses an
  `@mdi` icon. The **`NcAppNavigationNew` "New request"** button sits at the top as the
  single prominent primary action.
- **Center `NcAppContent`** hosts the routed view.
- **Right `NcAppSidebar`** opens when a request is selected, with tabbed detail
  (Details / Coverage / Comments / History) — the standard Nextcloud master-detail
  pattern, used instead of a modal for *viewing* a request.
- Route with `vue-router`; deep-link each view (`#/my`, `#/approvals`, `#/team`,
  `#/hr/balances`, …).

### 15.2 Views

- **My leave** (`#/my`) — a **"next break" hero** (gradient card with a countdown to,
  or "enjoy your leave!" during, the soonest upcoming approved leave), then one compact
  **balance card** per counting leave type: an animated **balance ring** (the remaining
  number **counts up** on load) beside a **breakdown ledger** (base allowance
  + carry-over ± adjustment = entitlement, minus used and pending → **available**).
  Below, two **monthly `BarChart`s** for the current year — approved leave taken per
  month and sick days per month (always visible, empty months at zero) — then the
  list of my requests. Each row carries a
  leave-type **accent stripe** and a status chip, hover-lifts, and animates in/out via
  `<TransitionGroup>`. While loading, a **skeleton** placeholder shows instead of a
  spinner. The empty state uses an **animated palm illustration** + warm copy + CTA.
  Selecting a request opens the right `NcAppSidebar`.
- **New/Edit request** — an `NcModal` with: leave-type picker (`NcSelect` showing
  color+icon; employees only see **self-requestable** types — sick leave is excluded,
  §5.6), **From / To** date fields using the standard native picker
  (`NcDateTimePickerNative`, locale-formatted), and a required **Working days** number
  field (`NcTextField type="number"`) the employee fills in manually (§7). A **live
  preview** shows the balance impact as *before → after* plus a fill bar from the entered
  number. For types with `requires_replacement`, a **mandatory replacement picker**
  (`NcSelect` + org-wide user autocomplete, self excluded) appears. Optional reason
  (`NcTextArea`, labelled "(optional)" unless the type requires a note) and conditional
  note. Submit disabled until valid; negative `available`
  shows an inline `NcNoteCard type="warning"` (warn, don't block).
- **Record absence** (HR only) — the *same* dialog opened in **HR mode** from the HR
  nav: adds an **employee search** (`NcSelect` with user autocomplete) and offers *all*
  enabled types (including sick); the balance preview is hidden (it's another person's
  leave). The primary button reads **"Record"** (not "Submit request" — there is no
  request/approval flow). Submitting posts `employeeUid` and books the leave directly
  as approved (§5.6).
- **Approvals** (managers) — skeleton-then-list queues (team + escalated), rows with the
  same accent-stripe/transition treatment. Opening one reveals the request **progress
  stepper** and **coverage panel** (§8) in the sidebar with a conflict warning when the
  threshold is met. Approve / Reject as `NcButton` (primary / error); reject requires a
  comment; a successful approve updates the list quietly (no toast or confetti).
- **Team / Who's off** — a **Gantt-style month timeline** (`TeamTimeline`, §15.7):
  a sticky avatar rail with continuous rounded leave **pills** (colored by type, hatched
  while pending), weekend shading, a "today" line, month navigation + a "Today" jump,
  and a legend. `scope="team"` for managers, `scope="company"` for HR.
- **HR** (HR group only): *Balances* (searchable/sortable data table →
  entitlement/used/pending/remaining/carry-over, inline entitlement editor, skeleton on
  load), *Statistics* (stat tiles + a **`LineChart`** area for monthly trend and a
  **`DonutChart`** with legend for by-type, following the `dataviz` palette & a11y
  rules, themed to Nextcloud vars), *Who's off* (org-wide Gantt timeline), *Exports*
  (filter form + CSV buttons), plus entitlement / leave-type / holiday management.
- **Admin settings** — §12 (no personal settings page), via `NcSettingsSection`,
  `NcCheckboxRadioSwitch`, `NcTextField`, `NcSelect`.

All list/loading transitions and the illustration animations are suppressed
under `prefers-reduced-motion`.

### 15.3 Required @nextcloud/vue component inventory

Build exclusively from these (extend only for a genuine gap):
`NcContent`, `NcAppNavigation`, `NcAppNavigationNew`, `NcAppNavigationItem`,
`NcAppNavigationCaption`, `NcAppContent`, `NcAppSidebar`,
`NcAppSidebarTab`, `NcButton`, `NcModal`/`NcDialog`, `NcSelect`,
`NcDateTimePickerNative`, `NcTextField`, `NcTextArea`, `NcCheckboxRadioSwitch`,
`NcNoteCard`, `NcEmptyContent`, `NcLoadingIcon`, `NcAvatar`, `NcUserBubble`,
`NcCounterBubble` (nav badges), `NcActions`/`NcActionButton` (row menus),
`NcChip`/status pills, `NcListItem`, `NcSettingsSection`,
`NcDateTimePickerNative` (From/To in the dialog + HR filters), `NcTextField` (manual
working days). Icons from `@mdi/svg`. (The Dashboard widget is an API widget, §15.6, not
`NcDashboardWidget`.)

App-specific components built on top of the above (see §15.7): `BalanceRing`,
`BalanceCard`, `StatusChip`, `LeaveTypeChip`, `RequestListItem`, `RequestDialog`,
`RequestSidebar`, `RequestStepper`, `CoveragePanel`, `TeamTimeline`, `SkeletonList`,
`PalmIllustration`, `DonutChart`, `LineChart`, `BarChart`.

### 15.4 Visual language

- **Status colors** map to Nextcloud semantic vars — but chips render the label using
  the **contrast-optimised `--color-*-text`** variants (`--color-warning-text`,
  `--color-success-text`, `--color-error-text`; muted `--color-text-maxcontrast` for
  cancelled) on a **solid tint** (`color-mix(... 18%, --color-main-background)`) with a
  subtle border — so labels stay legible in both themes. `PENDING`/`ESCALATED` →
  warning, `APPROVED` → success, `REJECTED` → error, `CANCELLED` → muted,
  `WITHDRAWAL_PENDING` → warning.
- **Leave-type color** comes from `absence_leave_types.color` (§3.2) and is the single
  source for chips, the row **accent stripe**, calendar/timeline pills, chart segments,
  and ring segments — consistent everywhere. Leave-type chips pull their text 50%
  toward `--color-main-text` for contrast (colors are arbitrary/HR-defined).
- **Elevation & shape**: cards use `--border-radius-large` and hover
  `--color-background-hover`; never custom shadows outside Nextcloud tokens.

### 15.5 Microcopy & tone

Warm, concise, human, translatable. Examples (final strings via l10n):
empty my-leave → "Nothing booked yet — your next adventure starts here 🌴";
request sent → "On its way ✈️";
escalation → "Your manager's been quiet, so HR will take a look." Decisions are
deliberately quiet — approval shows no toast. Clarity always wins:
error and validation copy stays plain and helpful.

### 15.6 Dashboard widget (implemented, role-aware)

A Dashboard tile that adapts to the viewer's role:

- **Every employee** sees a balance summary line (remaining annual leave, used,
  pending) followed by their own upcoming/pending leave (type + date range + status).
- **Line managers** additionally see their team's requests awaiting a decision.
- **HR** additionally sees the escalated queue across the whole company.

Every item deep-links into the app (`#/requests/{id}` or `#/my`); the list is capped
at the dashboard's requested limit with a friendly empty state.

Implemented as an **API widget** (`OCP\Dashboard\IAPIWidgetV2` + `IAPIWidget` for
back-compat + `IIconWidget` for the palm-tree icon), registered via
`registerDashboardWidget()`. The core Dashboard renders the item list, so no separate
frontend bundle is required. (A richer custom-rendered variant with a balance ring and
grouped sections — via `OCA.Dashboard.register()` and its own JS entry — is a possible
future enhancement.)

### 15.7 Custom components & motion

Signature components built on top of `@nextcloud/vue`:

- **`BalanceRing`** — animated SVG donut of used vs. remaining for one leave type; the
  arc grows and the centre number counts up on load.
- **`BalanceCard`** — a My-leave card pairing a compact `BalanceRing` with the
  breakdown ledger (base + carry-over ± adjustment = entitlement, − used − pending
  → available, right-aligned beside the ring).
- **`TeamTimeline`** — Gantt-style month view: sticky avatar rail, continuous rounded
  leave pills (hatched while pending), weekend shading, "today" line, month nav.
- **`RequestStepper`** — horizontal progress stepper (Requested → Review/With HR →
  Approved/Declined/Cancelled/Withdrawing) shown atop the sidebar Details tab.
- **`RequestSidebar`** — master-detail sidebar with Details (stepper + facts + actions),
  Coverage, Comments, and History (§3.7) tabs.
- **`SkeletonList`** — shimmer placeholder shown while lists load (instead of a spinner).
- **`PalmIllustration`** — animated empty-state SVG (swaying palm, bobbing sun).
- **`DonutChart` / `LineChart`** — dependency-free, theme-aware SVG charts for HR stats
  (by-type donut with legend; monthly-trend area line). **`BarChart`** (same family)
  powers the monthly leave-taken and sick-days charts on My leave.
- **`StatusChip` / `LeaveTypeChip`** — contrast-optimised pills (§15.4).

**Motion policy:** every animation (ring count-up, list `<TransitionGroup>`, timeline
pills, skeleton shimmer, illustration, stepper pulse, chart draw) is disabled
under `prefers-reduced-motion: reduce`.

---

## 16. Internationalization

- Target the **latest stable Nextcloud (34)** with **full multi-language support**.
- All user-facing PHP and JS strings wrapped in translation functions
  (`$l->t(...)` / `t('absence', ...)`). Provide `l10n/` structure and `.pot`
  extraction via the standard Nextcloud transifex/`translationtool` setup.
- Dates/numbers localized via Nextcloud locale APIs; week-start respects locale.
- Seeded leave-type labels use translatable defaults.

---

## 17. Security & Data Protection

- Strict server-side authorization on every endpoint via a central `PermissionService`
  (is-owner / is-manager-of / is-HR / is-admin).
- An employee may only read their own data; a manager only their reports; HR all.
- Sick-leave reasons/notes are sensitive: never expose reason/note text to peers;
  restrict shared-calendar titles per the type-visibility setting (§10/§12).
- Full audit trail on three levels: per-request history (§3.7), the Activity feed,
  and an always-on `nextcloud.log` audit entry per action (§11) for all decisions,
  overrides, and balance edits.
- Input validation and rate-limiting on create/edit endpoints
  (`#[UserRateLimit]` where appropriate).
- GDPR: provide data via Nextcloud's user data export/deletion hooks — implement
  `OCP\User\Events\BeforeUserDeletedEvent` handling to anonymize/remove a deleted
  user's requests per policy, and register with the privacy/personal-data-export
  mechanism.

---

## 18. Testing

- **PHPUnit** unit tests for services (BalanceService, PermissionService, carry-over,
  the replacement/state-machine logic) and mappers, plus integration tests for
  controllers. Aim for high coverage on the balance/state-machine logic.
- **Frontend** unit tests (Vitest) for balance preview and date logic; component
  tests for the request form.
- **State-machine tests** covering every transition in §4, including edit/withdrawal
  and escalation edge cases and year-boundary accounting.
- Lint/format per repo standards (`composer cs:check`, `psalm`, `eslint`, `stylelint`).

---

## 19. Deliverables & App Skeleton

As built:

```
apps/absence/
├── appinfo/
│   ├── info.xml            # id=absence, ns=Absence, NC 34–36, nav entry, jobs, notifier, activity, repair steps, settings
│   └── routes.php          # app routes for the SPA + JSON API (§14)
├── composer.json           # OCA\Absence\ autoload, dev deps (phpunit, psalm, cs-fixer, nextcloud/ocp)
├── package.json            # frontend deps (@nextcloud/vue 9, vue 3, @nextcloud/vite-config)
├── vite.config.js          # inlineCSS injection → js/absence-*.mjs
├── psalm.xml
├── img/                    # app.svg (white), app-dark.svg (black) — account-clock glyph
├── lib/
│   ├── AppInfo/Application.php   # registers Notifier, Dashboard widget, UserDeleted listener
│   ├── Controller/         # Page, Request, Balance, Entitlement, Coverage, Calendar,
│   │                       # LeaveType, Report, Export, Config (+ ApiControllerTrait)
│   ├── Db/                  # LeaveRequest, LeaveType, Entitlement, RequestComment,
│   │                       # RequestEvent + matching QBMappers
│   ├── Service/            # ConfigService, ManagerResolver, PermissionService,
│   │                       # BalanceService, RequestService, CoverageService, CalendarService,
│   │                       # NotificationService, ActivityPublisher, ReportService, ExportService,
│   │                       # EntitlementService, SessionService
│   ├── BackgroundJob/      # EscalationJob, ReminderJob, YearRolloverJob
│   ├── Dashboard/AbsenceWidget.php   # role-aware API widget (§15.6)
│   ├── Notification/Notifier.php
│   ├── Activity/           # Provider + Setting
│   ├── Exception/          # AbsenceException + Validation/Forbidden/NotFound/Conflict
│   ├── Migration/          # Version1000Date… schema; Version1001/1002/1003 (columns);
│   │                       # SeedLeaveTypes; EnableAuditLogging / DisableAuditLogging (§11)
│   ├── Settings/           # Admin + AdminSection (no personal settings)
│   └── Listener/UserDeletedListener.php   # GDPR purge (§17)
├── src/                    # Vue 3 SPA: App.vue, router, store, api,
│   │                       # utils/ (dates),
│   │                       # views/ (MyLeave, Approvals, Team, hr/*, settings/AdminSettings),
│   │                       # components/ (BalanceRing, BalanceCard, StatusChip, LeaveTypeChip,
│   │                       # RequestListItem, RequestDialog, RequestSidebar, RequestStepper,
│   │                       # CoveragePanel, TeamTimeline, SkeletonList,
│   │                       # PalmIllustration, DonutChart, LineChart, BarChart)
│   └── {main,admin-settings}.js
├── templates/              # main.php, admin-settings.php (mount points)
├── tests/                  # phpunit.xml
├── README.md
└── SPECIFICATION.md        # this file
```

Note: `CalendarSyncJob` from the original plan was not needed — calendar writes are
synchronous on decision (§10), which is sufficient for phase 1.

**Definition of done for phase 1 — all met:**
- ✅ Employee can apply; manager can approve/reject; HR sees overview, stats, export.
- ✅ Full balance tracking with entitlements + carry-over.
- ✅ Full-day requests, configurable leave types, manually entered + manager-verified working-day counts (§7).
- ✅ Notifications (bell), email, activity, **and always-on server-log audit** for all state changes.
- ✅ Escalation to HR on manager non-response.
- ✅ Edit/cancel/withdraw workflow with balance restoration.
- ✅ Approved leave written to Nextcloud Calendar (personal + shared team), removed on cancellation.
- ✅ Coverage conflict warnings for managers.
- ✅ Per-request history timeline visible to employee/manager/HR (§3.7, §15.1).
- ✅ Role-aware Dashboard widget (§15.6).
- ✅ Multi-language, NC 34, lint-clean; core logic covered by executable tests.

**Phase 2 (nice-to-have):** file attachments for doctor's notes (§3.8), ICS import
for holidays, percentage-based coverage thresholds, department analytics dashboards,
half-day support (schema already allows it), a richer custom-rendered Dashboard widget,
mobile-tuned views.

---

## 20. Summary of Key Decisions (from requirements interview)

| Topic | Decision |
|-------|----------|
| Approval flow | Manager approves; HR escalation & override; HR handles no-manager cases |
| Manager source | `IUser::getManagerUids()` — the NC 34 manager account field (denormalized per request); no `IAccountManager::PROPERTY_MANAGER` exists |
| Leave types | Annual/paid, sick, unpaid, special (configurable; color + emoji icon; `employee_requestable` flag) |
| Sick leave | HR-recorded (no approval, not self-requested); visible to employee/manager/HR and on the shared calendar (§5.6) |
| Replacement | Mandatory for annual/unpaid/special (org-wide picker); replacement gets a push notification on approval and on cancellation (§5.1) |
| Balances | Full tracking: entitlement, used, pending, remaining, available, carry-over |
| Calendar | Nextcloud Calendar via CalDAV (personal + shared team) |
| Notifications | Nextcloud notifications + email + activity stream + always-on server log |
| Holidays | **None** — removed; working days are entered manually (§7) |
| Working days | Entered manually by the employee, verified by the manager (no auto-calc, no region) |
| Coverage | Team overlap view + conflict warnings for managers |
| Granularity | Full days only (schema allows future half-days) |
| HR reports | Per-employee balances, company trends/charts, CSV/Excel export, who's-off calendar |
| Out-of-office | Independent — does NOT touch built-in user_status |
| Lifecycle | Full edit workflow (edit/cancel/withdraw with re-approval + balance restore) |
| HR role | Configurable Nextcloud group (default `hr`) |
| Escalation | Auto-escalate to HR after a configurable pending window |
| History | Per-request timeline (§3.7) shown to employee/manager/HR |
| Dashboard | Role-aware API widget (§15.6) |
| Platform | Nextcloud 34 (compatible up to 36), standard PHP + Vue 3 app, full multi-language |

---

## 21. Implementation notes (deltas from the original design)

Recorded during the build so the spec matches the code:

- **Manager resolution** uses `OCP\IUser::getManagerUids()` (§2.1). The originally
  proposed `IAccountManager::PROPERTY_MANAGER` does not exist in NC 34 — the manager
  relationship lives on `IUser`, not in the account-properties list.
- **Always-on audit logging** (§11) was added: a structured `nextcloud.log` entry per
  important action, forced regardless of log level via a merged `log.condition.apps`
  repair step (`EnableAuditLogging` / `DisableAuditLogging`).
- **Per-request history** (§3.7, `absence_request_events`) was added and surfaced in
  the sidebar History tab, written from the same `audit()` call site.
- **Dashboard widget** (§15.6) implemented as a role-aware `IAPIWidgetV2` (no custom
  frontend bundle).
- **Leave types** carry an `icon` (emoji) column in addition to `color` (§3.2), used
  consistently across chips, calendar, timeline and the widget. UI chips use
  Nextcloud's contrast-optimised `--color-*-text` variables on solid tints for
  readability in both themes (§15.4).
- **App icon** is a palm tree: `img/app.svg` is white (for the coloured top bar, which
  the server inverts on bright backgrounds); `img/app-dark.svg` is black (for light
  surfaces — settings, notifications, activity).
- **Default entitlement** falls back to the configured default only for the `annual`
  type; other counting types start at zero until HR grants an entitlement, and
  carry-over rollover only processes employees/types that already had an entitlement
  (avoids fabricating balances).
- **Frontend build**: standalone per-app `@nextcloud/vite-config` with
  `inlineCSS: { relativeCSSInjection: true }`, so a single `Util::addScript` styles the
  whole app (no separate stylesheet to enqueue). Output: `js/absence-*.mjs`.
- **CalendarSyncJob** was dropped — synchronous CalDAV writes on decision suffice for
  phase 1 (§10, §19).
- **Platform range** widened to NC 34–36 in `info.xml`.
- **Sick leave is HR-recorded, not self-requested** (§5.6): added an
  `employee_requestable` flag to leave types (sick = false), a migration to add it on
  existing installs (`Version1002…`, app bumped to 1.0.2), HR create-on-behalf via an
  `employeeUid` on `create`, and a "Record absence" HR action (employee search + all
  types) in the frontend. Employees no longer see sick leave in their request picker.
  The "Approved" chip and approval stepper are hidden for HR-recorded approved leave,
  and only HR may edit/cancel it (`canModify`).
- **Manual working days; no holidays/region** (§7): removed the automatic working-day
  calculation and the whole public-holiday/region concept. The employee now enters
  `working_days` (validated `> 0`), the manager verifies it, and balances/trends attribute
  it to the request's start year/month. Deleted `WorkingDayCalculator`, the
  `Holiday`/`HolidayMapper`/`HolidayController` + routes, the personal settings page
  (region), the admin "default region" option, the frontend `RangeCalendar` + client-side
  working-day helpers; the request dialog uses the standard native date pickers plus a
  manual "Working days" field. App bumped to 1.0.4. (The `absence_holidays` table is left
  as an unused orphan on already-installed instances.)
- **Mandatory replacement** (§5.1): a `requires_replacement` leave-type flag (annual/
  unpaid/special = true) + a `replacement_uid` on requests (`Version1003…`, app bumped
  to 1.0.3). The request dialog shows a mandatory org-wide user picker; the backend
  validates it; the replacement gets a **push notification** on approval and on
  cancellation of approved leave (new `replacement_assigned` / `replacement_cancelled`
  notification + email subjects). Shown in the request sidebar.
- **UI polish pass** (§15.2, §15.7): skeleton loaders, leave-type accent stripes +
  list transitions, count-up balance rings, a "next break" hero, an animated palm
  empty-state, approval confetti (since removed, see below), a Gantt-style team
  timeline, a request progress
  stepper, a visual range-calendar picker with presets + a live balance bar, and
  upgraded HR charts (donut + area line + stat tiles). All motion respects
  `prefers-reduced-motion`.
- **My-leave overview & quieter decisions** (§15.2, §15.7): each balance ring gained a
  **breakdown ledger** beside it (`BalanceCard`: base + carry-over ± adjustment =
  entitlement, − used − pending → available) and the view gained two **monthly
  `BarChart`s** (approved leave taken, and sick days, for the current year — always
  visible, empty months at zero; multi-month requests are split across months
  pro rata by calendar days). The approval **confetti and "Approved — enjoy! 🎉"
  toast were removed** (decisions are quiet; the status chip is the feedback), the
  sidebar's dangling Settings link was dropped (no personal settings, §12), the
  reason field is labelled "(optional)" when the type doesn't require a note, and the
  HR record dialog's primary button reads **"Record"** instead of "Submit request".
  Fixed: `workingDays` was not accepted by the create endpoint (every new request
  failed validation), and page titles now clear the floating navigation-toggle button.
