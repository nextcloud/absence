<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
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
| **Employee** | Every logged-in user **except guest accounts** (§2.2) | Create/edit/cancel own requests, view own balance, view own history, see team who's-off calendar. |
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
- **The inverse direction never enumerates the user backend.** `isManagerOf()`
  is answered from the *employee's* own manager property (one user lookup — it
  runs inside `canView`/`canDecide` on nearly every request), and
  `getDirectReports()` reads the stored `settings/manager` preference for all
  users in a single indexed query (`IUserConfig::getValuesByUsers()` — the
  server persists `IUser::getManagerUids()` there as a JSON list), resolving
  the first-valid-manager rule in memory. Walking every account per request
  made each click O(headcount) on large LDAP instances.
- **No manager found:** the request is created with `manager_uid = NULL` and is
  routed directly to HR (treated as immediately escalated — see §5.4).

### 2.2 Who counts as an employee (guest accounts)

Not every account on an instance is a member of staff. **Guest accounts — users
created by the [Guests app](https://github.com/nextcloud/guests) — are external
people invited to collaborate on files. They have no entitlement and take no
leave, so the app does not treat them as employees.**

Without this rule every guest would sit in the balances report and the who's-off
calendar forever, with an empty allowance and nothing to show.

- **One definition, one place.** `EmployeeDirectory` is the only component that
  enumerates users; `ReportService`, `EntitlementService`, `CoverageService` and
  `ManagerResolver` all ask it rather than walking `IUserManager` themselves. A
  rule stated in four copies is a rule that holds in three.
- **Detection.** A guest is a user in the Guests app's own user backend, i.e.
  `IUser::getBackendClassName() === 'Guests'` — the same thing
  `OCA\Guests\GuestManager::isGuest()` checks. Read this way the app needs **no
  dependency on the Guests app**: where it is absent or disabled, no account has
  that backend and the rule is simply never true.
- **Consequences.** Guests do not appear in balances, statistics, the sick-leave
  overview, exports, the who's-off calendar, the HR absence list or any people
  picker; they are nobody's direct report or peer, and cannot be resolved as a
  line manager (a request routed to one could never be approved).
- **Enforced, not just hidden.** The API rejects creating leave for a guest —
  including by HR, who may otherwise record for anyone — nominating a guest as a
  replacement, and setting a guest's entitlement. Filtering only the UI would
  leave the rule one crafted request away from being bypassed.
- **Pickers.** The people pickers call the app's own
  `GET /api/employees/search` rather than core's autocomplete, because only the
  server can tell a guest from a colleague. That endpoint wraps the same
  collaborator search, so the admin's user-enumeration settings still apply
  exactly as elsewhere; guests are removed from what it returns. It does add one
  person that search always withholds — **the searching user themselves**, whom the
  collaborator search drops because it was built to answer "who can I share with".
  Here the question is "whose absence is this?", and there you are a valid answer:
  without it an HR member cannot record their own sick leave, since the dialog will
  not submit without an employee and the self-service route offers only the
  self-requestable types (§5.6). Returning you to yourself discloses nothing, so
  this one result is not gated on enumeration settings.
- Existing records for someone who later becomes a guest are left untouched in
  the database — they simply stop being listed.
- **Optional employees group (§12).** An admin can narrow "everyone who is not a
  guest" down to the members of one Nextcloud group. Left empty — the default —
  the rule stays as above. Configured, only members of that group count as
  employees: everyone else is excluded exactly like a guest (no leave — enforced
  by the API, not just hidden —, no entitlement, absent from reports, pickers and
  the who's-off views), which keeps service and functional accounts out. It also
  bounds enumeration: `EmployeeDirectory::listAll()` reads that one group instead
  of walking the entire user backend, which matters on large LDAP instances. The
  setter refuses a group that does not exist; should the configured group be
  deleted later, the app **fails open** (every non-guest account counts again) and
  logs an error — failing closed would silently freeze leave booking for the whole
  company.

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

Indexes: `(employee_uid, status)`, `(manager_uid, status)`, `(start_date, end_date)`,
`(type_id)`, `(status, created_at)` (escalation/reminder scans and the HR queue) and
`(supersedes_id)` (the supersedes chain is read inside every edit and approval).

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

The `hr_only` flag (§5.7) marks a type as confidential: recorded by HR and
visible only to HR. Seeded confidential types: `maternity`, `work_prohibition`,
`doctors_note`, `parental` — added idempotently on update too (`SeedConfidentialLeaveTypes`),
so existing installations receive them without touching HR customisations.

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
| `detail` | text, nullable | Human-readable extra. For an edit this is the **difference**, not the result: `Working days 3 → 5 (+2); Reason “Wedding” → “Wedding (extended)”`. Recording only the resulting state cannot answer what anybody opens the history to ask — what changed and by how much — and a day count means nothing without the number it replaced. On creation it carries the type, dates, day count and the employee's reason, since the request itself only ever shows its *current* state. |
| `created_at` | datetime | |

Events are written by the same `audit()` path that emits the server-log entry (§11),
so history, server log and activity stay in sync from a single call site. History
writes are best-effort — a failure never blocks the workflow.

### 3.7b `absence_entitlement_events` (entitlement history)

The same idea for entitlements, which had no timeline at all: §3.7 is keyed on
`request_id`, and an entitlement belongs to no request, so an adjustment left only a
server-log line and an activity entry reading "Leave balance of X was adjusted" —
with neither the amount nor the reason. Worse, the note HR is *required* to give
when adjusting was stored on the entitlement row, displayed nowhere, and overwritten
by the next adjustment.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | |
| `entitlement_id` | bigint, indexed | The entitlement this change belongs to. |
| `employee_uid` | string(64), indexed | Denormalised from the entitlement so the GDPR purge (§17) and per-person views need no join to a row that is about to be deleted. |
| `actor_uid` | string(64) | Who made the change. |
| `field` | string(32) | `base_days`, `carry_over_days` or `manual_adjustment`. |
| `old_value` / `new_value` | float | The figure before and after; the delta is derived. |
| `note` | text, nullable | The reason given, attached to every figure that save touched. |
| `created_at` | datetime | |

**One row per changed figure, not per save,** so "+2 days for the wedding" reads on
its own. A save that moves nothing writes nothing. Surfaced in the entitlement
editor in HR → Balances, and carried into the activity entry so it says what
changed rather than only that something did. Best-effort, like §3.7: an unwritable
history must not cost HR the adjustment they just made.

### 3.8 Attachments

Files on a leave request — the doctor's note above all. Metadata lives in
`absence_attachments` (id, request_id, uploader_uid, name, mime, size,
created_at); the bytes live in the app's **appdata** storage, keyed by the
row id, never in anybody's Files — a document in a user's home could be
renamed, shared or deleted underneath the record, and its visibility could
not be governed by this app.

Visibility is deliberately narrower than `canView()`: **HR and the employee
only** — the manager reads the request, not the medical documents on it. On
confidential requests (§5.7) even the employee sees none. Upload: HR always;
the employee on their own non-terminal, non-confidential request (which
includes HR-recorded sick leave — exactly where a doctor's note belongs).
Delete: HR, or the uploader. Limits: 10 MB per file, 10 files per request.
Attachments never appear in the request-history timeline (it is
manager-visible); adds and removals go to the always-on audit log. The GDPR
purge removes rows and bytes. API: §14; the earlier `attachment_note`
free-text field remains for a quick remark.

### 3.9 Which "today" applies (`ClockService`)

Leave is stored as **dates**, not instants: `start_date` and `end_date` are the days
the employee typed on a calendar. Comparing them against "now" therefore needs a *day
boundary*, and a day boundary only exists relative to a timezone.

Nextcloud pins PHP's default timezone to UTC for the whole request, so a bare
`date('Y-m-d')` answers in UTC wherever anybody is. For a timestamp that is correct.
For a day boundary it is a bug: at 09:00 on 2 January in Auckland it is still
1 January in UTC, so an employee booking leave for today would be told it "is
entirely in the past" for a day that has not finished where they live. Berlin has the
same fault in the other direction for the last hour of every day.

Which boundary is right depends on **who is asking**, so `ClockService` exposes the
two separately rather than one ambiguous `today()`, and every caller has to choose:

| | Used for | Examples |
|---|---|---|
| `userToday()` / `userYear()` | anything an employee sees or is judged against, in *their* timezone | the "not entirely in the past" validation (§5.1), the dashboard widget's upcoming leave, the default year for balances, reports and exports |
| `serverToday()` / `serverYear()` | company-wide policy and background jobs, where there is no user to ask | short-notice measurement (§8), carry-over expiry and the year rollover (§6.2, §9) |
| `now()` | stored timestamp columns (`created_at`, `decided_at`, …) | deliberately UTC — a timestamp records *when* something happened, which is the same moment for everyone |

Company-wide policy uses the server's clock on purpose: one request must get one
answer whether the manager, HR or the job that mails them is asking, and a warning
that changed depending on the reader's timezone would be worse than no warning.

The instant itself comes from `ITimeFactory` so tests can pin it — `new \DateTime()`
cannot be frozen.

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

   **The requirement is on the employee, not on the record.** It exists because somebody
   arranging their own leave knows who can cover and is asked to sort it out before
   going. When HR records or corrects an absence *for somebody else* (§5.5, §5.6) they
   are stating a fact, often after the event, and cannot nominate cover on that person's
   behalf — so there the field is offered but never demanded, and reads "Who is the
   replacement?" rather than "Who covers for you?". HR recording their *own* absence
   gets the self-service wording and requirement, since it is their leave.

   Who may be named is *not* conditional: a replacement must be an employee (not a
   guest, §2.2) and not the person being covered for, whether or not the type demands
   one.
4. On submit, backend:
   - Validates dates (`start ≤ end`, not entirely in the past — in the *employee's*
     timezone, §3.9 — unless HR, not
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
  **Only one edit may be in flight per approved request:** while a superseding
  request is non-terminal, further edit attempts on the original are rejected
  (409) — otherwise two edits could both be approved and overlap.
- **Approved request — withdraw:** employee requests withdrawal → status
  `WITHDRAWAL_PENDING`; manager/HR must approve the withdrawal. On approval →
  `CANCELLED` (balance restored); on rejection → back to `APPROVED`.
  **Not while an edit is in flight** (409, same rule as above and for the same
  reason): the edit excludes the original from its overlap check as part of the
  supersedes chain, so an original sitting in `WITHDRAWAL_PENDING` when the edit is
  approved would leave both in force — the same leave counted twice, and a declined
  withdrawal would put two overlapping `APPROVED` requests on the same dates. Cancel
  the edit first, then withdraw. Correspondingly, retiring a superseded request
  treats `WITHDRAWAL_PENDING` as still in force, so rows written before this rule
  still retire cleanly.
- **Cancellation of any non-terminal request** restores pending/used balance.

### 5.4 Escalation (manager non-response)

- Background job (§9) scans `PENDING` requests older than the configured
  **escalation window** (admin setting, default 3 working days since creation with
  no decision).
- Such requests are marked `ESCALATED` (`escalated = true`), and HR is notified
  (notification + email + activity). HR can then approve/reject on the manager's behalf.
- **Race-safe:** the job picks its candidates from a list read moments earlier, and a
  manager may decide one of them in between. The flip is therefore a single conditional
  `UPDATE … WHERE status = 'PENDING'` (`LeaveRequestMapper::markEscalated()`), never an
  entity write-back — so a fresh decision cannot be clobbered back to `ESCALATED`, and
  nobody is notified about an escalation that did not happen.
- Requests with no manager (§2.1) start life effectively escalated and are surfaced
  in the HR queue immediately.

#### 5.4a Manager-absence awareness

The app knows when the decider is away — it manages their leave too:

- **At submission:** if the assigned manager has an *approved* absence covering
  today (server calendar), the request is created `ESCALATED` straight away,
  with the history saying so ("Manager is away — routed to HR"). Waiting the
  full window to tell HR what the app already knows would only delay the answer.
- **Hourly:** the escalation job additionally escalates pending requests whose
  manager is away today *and* stays away beyond that request's own escalation
  deadline (created + window, working days) — they cannot possibly decide in
  time. Both paths use the race-safe conditional flip (§5.4), so a manager
  deciding at that very second is never clobbered.
- Deliberately **not** given to the manager's replacement: deciding leave means
  reading reasons and balances, and covering someone's duties does not come
  with that. HR decides, as for every escalation.

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
- **Correcting a record.** HR reaches individual records through the **Absences**
  view (§15.2) — or by selecting one in *Who's off* / the *Sick leave* drilldown — and
  edits or cancels it from the standard detail sidebar. There is **no delete**: a wrong
  entry is cancelled (`CANCELLED`), so the row and its history stay intact for the audit
  trail (§17). HR's cancel skips the withdrawal step and applies immediately (§5.5).
- **No "Approved" label shown.** Since approval isn't a concept for HR-recorded leave,
  the UI hides the status chip and the approval **progress stepper** when such a request
  is `APPROVED` (a cancelled one still shows its status). The leave-type chip (e.g.
  "🤒 Sick leave") still identifies it.

---

### 5.7 Confidential leave types (HR-only visibility)

Some absence categories are nobody's business but HR's: **maternity leave**,
**parental leave**, a **medical work prohibition** (Beschäftigungsverbot) and a
**doctor's note** are seeded (§3.2), all flagged `hr_only`. HR can add, rename or disable such
types like any other; the API refuses an `hr_only` type that is self-requestable
(the invariant is what makes the visibility rules below coherent).

Rules, enforced server-side at every surface:

- **Recorded by HR only** — `hr_only` implies `employee_requestable = false`,
  so the existing §5.6 machinery applies: booked straight to `APPROVED`, no
  approval workflow, HR-only edit and cancel paths.
- **The category is visible to HR alone.** Everyone else — the line manager
  *and the employee's own views* — sees the absence as a neutral "Absent":
  dates and status, no type, no reason, no notes, no comments, no history.
  Withholding happens at serialization (`typeId` is nulled; the client already
  renders a null type as "Absent"), in the coverage/timeline feeds (where the
  admin's "reveal" visibility setting deliberately does not extend to these
  types), in balance rows, in the dashboard widget, and in both CalDAV event
  titles (a personal calendar can be shared, so even the employee's own event
  says only "Absence").
- **Presentation:** in the Record absence dialog the confidential categories do
  not appear as top-level leave types. HR picks **Sick leave** and a *Category*
  sub-select appears — *General sick leave* (default), *Maternity leave*,
  *Parental leave*, *Medical work prohibition*, *Doctor's note*. The chosen category's type id is
  what gets stored, so everything downstream (statistics, sick-leave report,
  exports, confidentiality) still works per type. Editing a confidential record
  re-opens as Sick leave + its category. Should no type keyed `sick` exist, the
  confidential types fall back to being listed directly.
- **The type list itself is filtered**: non-HR clients never receive the
  confidential types in `GET /api/leave-types` or the SPA bootstrap payload —
  the names are the sensitive part.
- **Decisions**: `canDecide()` is false for non-HR on confidential requests,
  categorically. `canView()` is deliberately *not* narrowed — the bare fact of
  the absence is already on the team timeline, so the employee and manager may
  open the (withheld) record; refusing would only turn timeline clicks into
  errors without hiding anything.
- **The bare fact stays visible everywhere it must**: overlap checks, coverage
  counts, the who's-off timelines and the team calendar all treat a
  confidential absence like any other — colleagues plan around the absence,
  not the reason.
- HR-only surfaces (absences list, statistics, sick-leave report with its type
  picker, exports) show confidential types in full.

---

### 5.8 The disability flag (HR-only)

HR can mark an annual-leave request as **disability-related** — the additional
statutory entitlement for employees with a recognised disability. Rules:

- **Set by HR alone**: offered in the record/edit dialog when the type is
  annual leave; the API silently ignores the field from anyone else (the
  request itself still succeeds).
- **Seen by HR alone**: serialization nulls the flag for every non-HR viewer —
  the employee's own views included — and the request-history timeline never
  mentions it (`describeChanges()` deliberately excludes it, since the history
  is visible to the employee and manager). Changes are recorded in the
  always-on server log instead.
- The flag rides on the request row (`disability`, boolean); it does not alter
  balances or workflows.

---

## 6. Balances & Entitlements

### 6.1 Entitlement management (HR)

- HR sets `base_days` per employee per year per counting type via the HR area.
- Bulk actions: set a default entitlement for a whole group/all employees for a
  given year (admin default in settings, §11, used to seed).
- Manual adjustments (+/−) require an `adjustment_note`.

### 6.3 CSV import (onboarding)

`occ absence:import-entitlements <file.csv> [--year N] [--dry-run]` brings
current entitlements over from the spreadsheet every company migrates away
from. Columns (header required, order free): `user` (uid or e-mail),
`base_days`, `carry_over_days`, `adjustment` (absolute), `note`, `type`
(key, default `annual`), `year` (default: `--year`). Comma or semicolon
separated (German Excel), BOM tolerated. **All-or-nothing:** every broken
row is reported and nothing is written — half an imported company is the
worst outcome of a typo. Writes go through `EntitlementService`, so the
entitlement history records the import like any HR edit.

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
- The server validates only that it is a positive, sane number (`> 0`, `≤ 366`, and **at
  most one working day per calendar day of the leave** — a 3-day range cannot carry 40
  working days; the typo guard never rejects a legitimate count); it never recomputes
  it. `working_days` is stored as entered and is authoritative.
- **Accounting simplification:** because there is no day-by-day breakdown, a request's
  working days are attributed wholly to the **year (for balances, §3.4) and month (for
  trends, §13) in which it starts**. Year-boundary requests are rare; split them into two
  requests if precise per-year accounting is needed.

> Consequences of removing holidays: there is **no `WorkingDayCalculator`, no public
> holidays feature (`absence_holidays`)**, and the admin "default region" option is gone.
> The entered value is always authoritative; the server never recomputes it.

The dialog labels the prefilled count as **an estimate that must always be
checked and adjusted manually** — in the self-service flow *and* in HR's
record-absence flow. The warning is part of the field, not fine print: the
count drives the balance, and the server never recomputes it (§7).

### 7.1 Client-side prefill (convenience only)

To save typing, the request dialog **prefills** the working-days field with a
client-side estimate: days in the picked range that fall on the user's working
weekdays (detected from their Availability settings, overridable) minus public
holidays (from the bundled `date-holidays` data, for a country/region the user may
set; detected from locale/phone as a suggestion). The estimate is served by
`PersonalDefaultsService` + `/api/personal/config`, with a small personal-settings
section appended to the built-in **Availability** page (`lib/Settings/Personal.php`).
The prefill stops as soon as the user edits the field, is absent when editing an
existing request, and is **never used server-side** — the manually confirmed number
is what counts (§7).

---

## 8. Team Coverage & Conflict Warnings

- **Team calendar / who's-off:** managers see their direct reports; HR sees the
  whole company; every employee sees their own team (peers who share the same
  `manager_uid`). A month/timeline view rendered from approved + pending requests.
  - **Whose leave type is shown.** The type travels with an event when the admin set
    the shared-calendar visibility to `reveal`, or when the viewer is somebody who
    could open that request and read the type off it anyway — its owner, their line
    manager, or HR (§2, `canView`). Everyone else gets `typeId: null` and the client
    labels the absence generically. With no viewer the type is withheld from all
    (fail-closed).
    - The policy protects a *colleague's* privacy — a peer must not learn that
      somebody is on sick leave. It is not a restriction on HR, who record sick
      leave, nor on the line manager who approved the absence, and withholding it
      from them only degraded their own view: with no type to label the absence,
      the client fell back to a generic marker, so an HR timeline of sick
      colleagues read as a row of holidays.
    - The generic marker must be **neutral about the reason**. Withholding why
      somebody is away and then implying a cheerful reason is worse than either
      revealing it or saying nothing.
- **Conflict warning:** when a manager reviews a request, compute the maximum number
  of concurrently-absent team members on any day in the requested range. If it meets
  or exceeds a configurable threshold (admin setting **max concurrent absences per
  team**, default e.g. 2, or a percentage), show a prominent warning in the review
  panel. It is a warning, not a hard block.
- **Short-notice warning:** a request whose leave starts sooner than the admin's
  **expected notice period** (calendar days, default 14; `0` switches the check off)
  is flagged to the line manager and to HR. Like the conflict warning it informs a
  decision and blocks nothing, and it appears wherever the decision is made: on the
  request's Details tab, in the notification and in the subject line of the email
  that asks for a decision (§11) — including the escalation to HR and the pending
  reminder, by which point the notice given has shrunk further.
  - Calendar days, not working days: "two weeks' notice" is a fortnight on the wall
    calendar. Measured against the *server's* today (§3.9), so one request gets one
    answer for the manager, for HR and for the job that mails them.
  - Only while a decision is outstanding (`PENDING` / `ESCALATED`), and so never for
    leave with no approval workflow — sick leave is recorded after the fact and
    auto-approved types are booked straight through (§4.1), so nobody is weighing the
    notice, and nobody can give notice of falling ill.
- **Before the ask, not only after it:** the request dialog runs the same team-scope
  overlap query as the dates are picked and names the colleagues already off then,
  as an `info` note — or a `warning` when booking would take the team to the
  concurrency threshold. It is advisory in the strongest sense: it never disables
  submit, a failed lookup simply omits the hint, and the employee stays free to book
  a clash they have already agreed with their team. The point is that the person
  choosing the dates learns what the manager will see at the moment they can still
  cheaply choose differently.
  - Shown only for one's *own* leave. The endpoint answers for the caller's team, so
    HR recording an absence for somebody else would otherwise be shown the wrong
    team's names, and no hint beats a misleading one.
  - Leave *types* are neutralised by the same shared-calendar visibility policy that
    governs the timeline — a colleague's sick leave does not become visible because
    somebody opened the booking form.
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
  reminder (→ manager), withdrawal request (→ manager/HR), **comment added**
  (→ the employee and their manager, plus HR once the request has been escalated;
  never back to the comment's own author), **replacement assigned**
  (→ replacement, on approval) and **replacement cancelled** (→ replacement, when
  approved leave is cancelled) — §5.1. These are pushed (a standard NC notification is
  delivered to push automatically).
- **Actionable where the recipient owes an answer.** The four notifications that ask
  for a decision — new request, escalation, reminder, withdrawal — carry three
  buttons: **Approve**, **Decline** and **Review**.
  - *Approve* is a `POST` straight to `request#approve`, the same endpoint the app
    uses, so the common answer costs one click and no page load. The notification
    dismisses itself on success; a request whose state moved on in the meantime
    fails the same way it would in the app.
  - *Decline* is deliberately **not** a one-click verdict. A decline requires a
    reason (§5.2), and a manager able to reject somebody's holiday from a toast
    without saying why would be a worse app, not a faster one. It is a `WEB` link
    to `#/requests/{id}?decide=decline`, which opens the request with the reason
    box already unfolded — a step better than *Review* for someone who has decided
    to say no, and still a decision they have to confirm.
  - A withdrawal asks the opposite question, so its buttons read **Approve
    withdrawal** / **Keep leave**, matching the sidebar's wording (§15.2).
  - Notifications that merely report an outcome (approved, declined, comment,
    replacement) carry no decision buttons: nothing is owed on them.
- **Email:** via `OCP\Mail\IMailer` with templated messages
  (`OCP\Mail\IEMailTemplate`) for each of the above events. Respect the user's
  configured email + language.
- **What people wrote travels with the message.** Free text on a request — the
  applicant's `reason`, the `decision_comment`, the body of a comment (§3.6) — is
  carried by the notification and the email that announce the event, attributed to
  its author. A recipient must never have to open the app to find out what was
  actually said. The email quotes the text in full; the notification, which renders
  on one line, carries a whitespace-collapsed opening of it and where a note is
  present it takes the place of boilerplate like "Review it in Absence.".
  One deliberate exception: the replacement (§5.1) is told the dates only, never the
  reason — cover duty does not come with a right to read it.
- **Activity:** implement `OCP\Activity\IProvider` / setting so all state changes
  appear in the Activity app feed, filterable to an "Absence" activity type. Include
  activity for HR overrides and balance adjustments.
- **Server-log audit trail (always-on):** every important action is written to
  `nextcloud.log` as a structured entry tagged `["app" => "absence"]` with a
  machine-readable `action` and full context (actor, request id, employee, type,
  dates, working days, status) plus, where the action carried free text, that text
  itself — `detail` for a comment body or decision comment, `reason` for the note
  the applicant wrote on creation. Covered actions: the full request lifecycle (create,
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
| Employees group | empty (= everyone) | Optional: only members count as employees (§2.2). |
| Default annual entitlement (days) | 28 | Seed for new entitlement rows. |
| Escalation window | 3 working days | For EscalationJob (§5.4). |
| Reminder lead time | 1 day before escalation | For ReminderJob. |
| Carry-over policy | `capped` | `none` / `unlimited` / `capped`. |
| Carry-over cap (days) | 5 | Used when `capped`. |
| Carry-over expiry | none / date (e.g. Mar 31) | §6.2. |
| Max concurrent team absences | 2 | Conflict threshold (§8). |
| Expected notice period | 14 calendar days | Short-notice threshold (§8); `0` disables. |
| CalDAV: write personal events | true | §10. |
| CalDAV: write shared team calendar | true | §10. |
| Shared calendar type-visibility | neutral | Reveal type vs "Absent" on shared cal. |
| Leave types | seeded (§3.2) | Add/edit/enable/disable, colors, flags. |

The only personal settings are the **working-day prefill** preferences (§7.1) — a
small section appended to the built-in Availability page, not a separate Absence
settings page. Notification preferences defer to the global Nextcloud notification
settings.

> **Note on out-of-office:** the app does **not** touch the built-in
> `user_status` out-of-office / auto-responder feature. Calendar busy state (§10) is
> the only presence signal it sets.

---

## 13. HR Overview, Statistics & Export

The HR area (visible only to HR-group members) provides:

1. **Per-employee balances table:** entitlement / used / pending / remaining /
   carry-over, per year and type. Filterable by group/department, searchable,
   sortable. Drill-down to an employee's request history. The usage sums are
   aggregated by the database (`SUM(working_days)` grouped by employee, type and
   status for the report year), so the report scales with headcount, not with
   how many years of requests have accumulated; only the netting rule for
   pending superseding edits (§5.3) is applied in PHP, to the handful of rows
   it can concern. Single-employee balance views load only the requested year
   for the same reason.
2. **Company-wide trends (charts):** absence days over time (by month), by leave
   type, by department/group; headcount-on-leave heatmap. Stat tiles show approved
   leave days in the range, the **average sick days per employee over the calendar
   year** (total sick days ÷ all employees, not just the affected ones), the
   busiest month, and the number of leave types used. Below the charts, a **"most
   vacation still to take"** list ranks employees by *available* annual days
   (neither taken nor booked) for the year of the range's end date, top 10,
   flagging anyone with more than half their entitlement still unplanned — the
   view HR uses to nudge people before the year closes. Use a lightweight charting
   approach consistent with Nextcloud (e.g. `vue-chartjs`/Chart.js already used
   elsewhere in the ecosystem — confirm a bundled option; otherwise a small SVG
   chart component). Follow the `dataviz` design guidance for palette/accessibility.
3. **Who's-off calendar (org-wide):** all absences, filterable by team/type, for
   planning.
4. **Export:** CSV and Excel (`.xlsx`) export of raw requests and of the balances
   report, with date-range and group filters (the group filter narrows to the
   employees of one Nextcloud group; an unknown group yields an empty export, never
   a silently widened one), for payroll/external HR. CSV via native PHP; XLSX via a
   bundled library (e.g. PhpSpreadsheet) or a documented CSV fallback if a
   dependency is undesirable. The group pickers are fed by `GET /api/groups`
   (HR-only), so ordinary employees cannot enumerate the instance's groups through
   this app.

Managers get a scoped version (their reports only) of the who's-off calendar — the
Team timeline — and, below it, a **scoped balances table** of their direct reports
(entitlement / used / pending / available for the current year). It is served by
`GET /api/team/balances` (`balance#team`), which is not HR-gated: the manager
relationship itself is the permission — the same rule (`canViewBalanceOf`) that
already lets a manager read each report's balance one by one, and the request detail
already shows it to whoever may decide. The endpoint is always scoped to the
*caller's own* reports; for a non-manager it is simply empty.

---

### 13.1 In-app handbooks

Two handbook pages, served by the SPA itself (static, translatable content — no
backend), illustrated with element-level screenshots shipped under
`img/handbook/` (served via the app's image route; regenerate them alongside
`screenshots/` when the UI changes): `#/handbook`, linked in the sidebar for everyone, walks employees and
team leads through booking, editing, deciding, coverage, the Team page and the
who-sees-what rules; `#/hr/handbook`, linked in the HR section, is the extended
edition — the same chapters plus recording (incl. confidential categories),
absence management, entitlements, reports, the audit trail and the admin knobs
worth knowing. A non-HR visitor deep-linking to the HR edition is redirected to
the employee one; the HR chapters are documentation, not secrets, but the
handbook shown should match what its reader can actually see.

---

## 14. HTTP API (Controllers)

RESTful controllers under `lib/Controller/`, routes in `appinfo/routes.php`, all
guarded by the appropriate middleware/attribute-based access checks
(`#[NoAdminRequired]` for employee endpoints; explicit HR/manager checks in a shared
`PermissionService`). Use OCS or app routes consistently (app routes recommended for
the SPA). All list endpoints paginate and accept filters.

**Attachments (§3.8)**
- `GET  /api/requests/{id}/attachments` — list (HR + employee only; [] otherwise).
- `POST /api/requests/{id}/attachments` — multipart upload (field `file`).
- `GET  /api/attachments/{id}` — download (plain link; permission is the gate).
- `DELETE /api/attachments/{id}` — HR or the uploader.

**Requests**
- `GET  /api/requests` — list (scoped by role: own / reports / all-for-HR; filters: status, type, date range, employee, group).
- `POST /api/requests` — create (§5.1).
- `GET  /api/requests/{id}` — detail (with comments, coverage summary, and the
  employee's **balance** for that leave type in the year the leave starts — gated on
  `canViewBalanceOf`, so a colleague who may read the request still cannot read the
  allowance. Null for a type that counts against nothing. It is there because "took
  three days" says nothing about whether any are left, and finding out otherwise
  means abandoning the view for the Balances report).
- `PUT  /api/requests/{id}` — edit (§5.3; behavior depends on current status).
- `POST /api/requests/{id}/cancel` — cancel / request withdrawal.
- `POST /api/requests/{id}/approve` — manager/HR approve (optional comment).
- `POST /api/requests/{id}/reject` — manager/HR reject (comment required).
- `POST /api/requests/{id}/comments` — add comment.

**Balances & entitlements**
- `GET  /api/balance` — current user's balance (all years/types or filtered).
- `GET  /api/team/balances` — balances of the caller's own direct reports (§13);
  empty for a non-manager, never someone else's team.
- `GET  /api/employees/{uid}/balance` — manager (reports) / HR only.
- `GET  /api/entitlements` / `PUT /api/entitlements/{id}` — HR manage.
- `POST /api/entitlements/bulk` — HR bulk set.
- `PUT  /api/entitlements/{id}` — HR manage. **`adjustmentDelta` adds to** the stored
  manual adjustment; `manualAdjustment` **sets** it outright. Corrections are made as
  deltas ("+2 for the wedding", later "−2, booked in error") and must cancel to
  nothing — treating the second as an absolute set is what made 25 → +2 → 27 → −2
  land on 23 instead of back on 25. Sending both is refused rather than guessed at.
- `GET  /api/entitlements/{id}/history` — HR only: who changed which figure on an
  entitlement, from what to what, and the note they gave. One row per figure per
  save, so a single adjustment reads on its own rather than having to be diffed
  out of a blob.

**Coverage & calendar**
- `GET  /api/coverage?from&to&scope=team|company` — overlaps + conflict count (§8).
- `GET  /api/calendar?from&to&scope` — events for the in-app calendar/timeline.

**Reference data**
- `GET  /api/leave-types`.
- `GET  /api/groups` — group ids + display names for the HR report/export filters
  (HR only).
- HR/admin CRUD for leave types. (No holidays endpoints — the holidays/region
  feature was removed, §7.)

**HR reporting**
- `GET  /api/reports/balances` — balances report (filters).
- `GET  /api/reports/trends` — aggregated stats for charts.
- `GET  /api/reports/sick-leave` — sick-leave overview, every employee ranked by days
  lost. Counts the leave type keyed `sick` by default; `typeId` counts another type
  instead, which the *Sick leave* view exposes as a type picker. The response carries
  the types it aggregated, so the page can name what it is counting rather than
  implying "sickness" is a fixed concept — and can say so plainly when an instance has
  no matching type instead of showing a table of zeroes.
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
│   ├── NcAppNavigationItem    HR handbook      (extended handbook incl. the HR chapters)
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
  or "enjoy your leave!" during, the soonest upcoming approved leave). The countdown
  is in whole days until the leave is **under 48 hours away**, at which point it ticks
  in seconds (`H:MM:SS`, tabular figures) under the eyebrow *Almost there* — "1 day to
  go" is a poor description of an afternoon. The ticking element carries `role="timer"`,
  which is silent by default: an `aria-live` region here would read the clock aloud
  every second. The page's clock also keeps the day count honest across midnight for a
  tab left open, and only commits a new value when the rendered text can have changed,
  so a hero reading "12 days to go" does not re-render the request list once a second
  all year. Then one compact
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
  shows an inline `NcNoteCard type="warning"` (warn, don't block). A second inline
  note names the **colleagues already off** during the picked dates (§8) — debounced
  as the dates move, one entry per person, "and N more" past three.
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
  and a legend. `scope="team"` for managers, `scope="company"` for HR. With
  `selectable` the pills become buttons that open the request in the sidebar; only
  *Who's off* sets it, because HR may read every request the org-wide calendar shows
  while a team timeline can include leave the viewer is not allowed to open.
- **Absences** (`#/hr/absences`, HR only) — the counterpart to *Record absence*: the
  full list of recorded absences with filters for employee (user autocomplete), leave
  type, status and year, paged with a "Load more" button. Rows are the same
  `RequestListItem` as elsewhere and open the detail sidebar, whose **Edit** and
  **Cancel** controls are what let HR correct a wrong vacation or sick day (§5.6).
  People are named, never printed as user ids: requests are serialized with an
  `employeeName` (display name, falling back to the uid for a deleted account),
  and the sidebar names the employee under its title whenever the leave is not
  the viewer's own.
  Accepts `?employee=&employeeName=&type=&status=&year=` so other views can deep-link
  into it — the *Sick leave* overview does, from each employee row.
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
- **`PalmIllustration`** — animated empty-state SVG (swaying palm, bobbing sun) that
  **follows the calendar**: blossom and a passing bird in spring, full sun in summer,
  fronds turning and shedding in autumn, a snow-capped island and snowfall in winter.
  Meteorological seasons, flipped for southern-hemisphere users off the country
  already chosen for public holidays — no new setting, and no snow in a Sydney
  January. A `season` prop forces one for screenshots and tests.
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
│   ├── Settings/           # AdminDeclarativeSettings (server-rendered form) + AdminSection + Personal
│   └── Listener/UserDeletedListener.php   # GDPR purge (§17)
├── src/                    # Vue 3 SPA: App.vue, router, store, api,
│   │                       # utils/ (dates),
│   │                       # views/ (MyLeave, Approvals, Team, hr/*, settings/PersonalSettings),
│   │                       # components/ (BalanceRing, BalanceCard, StatusChip, LeaveTypeChip,
│   │                       # RequestListItem, RequestDialog, RequestSidebar, RequestStepper,
│   │                       # CoveragePanel, TeamTimeline, SkeletonList,
│   │                       # PalmIllustration, DonutChart, LineChart, BarChart)
│   └── {main,personal-settings}.js
├── templates/              # main.php, personal-settings.php (mount points)
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
- **Working-day prefill** (§7.1, added after the holidays feature was removed): the
  request dialog prefills `working_days` from the user's Availability weekdays and a
  lazily-loaded `date-holidays` dataset, configurable in a personal-settings section on
  the Availability page. Purely client-side convenience — the server still stores the
  number as entered.
- **Escalation & reminder windows count working days** (Mon–Fri approximation, §5.4):
  a request filed on Friday does not burn its manager's window over the weekend.
- **One in-flight edit per approved request** (§5.3): a second superseding edit is
  rejected with 409 while one is pending.
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
