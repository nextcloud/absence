<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# AGENTS.md

This file provides guidance to AI coding agents (e.g. Claude Code) when working with code in this repository.

## What this is

**Absence** is a Nextcloud app (app id `absence`, namespace `OCA\Absence`) for leave/vacation management: employees request leave, line managers approve, HR gets oversight, entitlements, and exports. PHP backend on the Nextcloud App Framework (NC 34–36, PHP 8.2), Vue 3 + `@nextcloud/vue` 9 frontend built with Vite.

**`SPECIFICATION.md` is the single source of truth** for the data model, request state machine, roles, workflows, API, and design system. Consult it before changing behavior; when implementation deviates from the original design, record the delta in its §21 "Implementation notes". §20 has a one-table summary of key decisions.

## Commands

Frontend (Node 24 / npm 11):
- `npm run build` — production build (outputs `js/absence-*.mjs`)
- `npm run watch` — development build with watch
- `npm run lint` / `npm run lint:fix` — ESLint
- `npm run stylelint` / `npm run stylelint:fix`
- `npm run test` — Vitest (specs live next to sources, e.g. `src/utils/dates.spec.js`)
- Single JS test: `npx vitest run src/utils/dates.spec.js`

PHP (via composer scripts):
- `composer cs:check` / `composer cs:fix` — php-cs-fixer
- `composer psalm` — static analysis (baseline in `tests/psalm-baseline.xml`)
- `composer lint` — `php -l` over all PHP files
- `composer test:unit` — PHPUnit (config `tests/phpunit.xml`)
- Single PHP test: `vendor/bin/phpunit -c tests/phpunit.xml --filter BalanceServiceTest`

**PHP tests require a Nextcloud server checkout**: `tests/bootstrap.php` requires `../../../lib/base.php`, i.e. the repo must sit at `apps/absence/` inside a Nextcloud server tree. Vitest, ESLint, psalm, and php-cs-fixer run standalone.

`make appstore` builds the signed App Store tarball (needs signing certs; rarely needed in development).

## Repo conventions

- **Compiled assets are committed.** `js/` contains the Vite output and is tracked in git. After frontend changes, run `npm run build` and commit the `js/` output as a separate `chore(assets): recompile` commit.
- Conventional-commit style: `feat(scope): …`, `fix(scope): …`, `chore(assets): recompile`.
- REUSE/SPDX compliance is CI-enforced: every file needs `SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors` and `SPDX-License-Identifier: AGPL-3.0-or-later` headers (or a `.license` sidecar / `REUSE.toml` entry).
- Tabs for indentation in both PHP and JS (Nextcloud style).

## Architecture

### Backend (`lib/`)

Request flow: `appinfo/routes.php` → thin `Controller` classes → `Service` classes (all business logic) → `Db` mappers (`OCP\AppFramework\Db\QBMapper` entities). Controllers share `ApiControllerTrait` for error mapping; services throw typed exceptions from `lib/Exception/` (ValidationException, ForbiddenException, ConflictException, NotFoundException).

Key services and their responsibilities:
- `RequestService` — the request lifecycle/state machine (statuses: PENDING, ESCALATED, APPROVED, REJECTED, CANCELLED, WITHDRAWAL_PENDING; see SPECIFICATION.md §4). Every important action goes through its `audit()` call site, which writes the per-request history (`absence_request_events`), the Activity stream, and an always-on structured `nextcloud.log` entry.
- `PermissionService` — server-side role checks. Four roles: employee (any non-guest user), line manager (from `IUser::getManagerUids()`, resolved by `ManagerResolver` and denormalized onto each request as `manager_uid` at submission), HR (configurable Nextcloud group, default `hr`), admin.
- `EmployeeDirectory` — the **only** component that enumerates users; it excludes Guests-app accounts (backend class `Guests`). Report/entitlement/coverage/manager code must go through it, never `IUserManager` directly.
- `BalanceService` / `EntitlementService` — entitlement, used, pending, remaining, carry-over accounting. Pending-ish statuses reduce `available`; APPROVED reduces `remaining`; rejection/cancellation restores days. Entitlement changes are recorded as `EntitlementEvent` history.
- `CalendarService` — synchronous CalDAV writes (personal + shared team calendar) on approve/cancel; there is no sync background job.
- `NotificationService` / `NoticeService` / `ActivityPublisher` — Nextcloud notifications (with approve-from-notification actions), email, and Activity for every step.
- `ConfigService` + `ConfigLexicon` — typed app config; admin settings are declarative (`Settings/AdminDeclarativeSettings.php`).
- `ClockService` — injectable clock; unit tests mock time via `tests/Unit/ClockMockTrait.php`.

Background jobs (`lib/BackgroundJob/`): `ReminderJob` (nudge managers), `EscalationJob` (auto-escalate stale PENDING requests to HR), `YearRolloverJob` (carry-over; only for employees/types that already had an entitlement). Requests with no resolvable manager are created with `manager_uid = NULL` and treated as immediately escalated to HR.

Leave-type semantics worth knowing: types carry `requires_approval` and `employee_requestable` flags. Sick leave is `requires_approval = false` + `employee_requestable = false`, so it is only ever recorded by HR on an employee's behalf (via `employeeUid` on create) and skips the approval flow. Working days are entered manually by the employee (validated > 0) — there is no automatic holiday/region calculation server-side.

### Frontend (`src/`)

Vue 3 SPA with hash-based `vue-router` (`src/router.js`): `/my`, `/approvals`, `/team`, `/hr/*`, plus `/requests/:id` deep links from notifications. State is a single lightweight `reactive()` store in `src/store.js` (no Pinia/Vuex), bootstrapped from `@nextcloud/initial-state` (`session`, `leaveTypes`) and talking to the backend through `src/api.js`. Views live in `src/views/` (HR pages under `views/hr/`), shared components in `src/components/`.

Two Vite entries (`vite.config.js`): `main` and `personal-settings`. CSS is inlined into the JS bundles (`inlineCSS: { relativeCSSInjection: true }`), so the PHP side only ever calls `Util::addScript` — never add a separate stylesheet.

UI is built from `@nextcloud/vue` components and must follow the design system in SPECIFICATION.md §15 (leave-type chips use the contrast-optimized `--color-*-text` variables, emoji icons per type, etc.). All user-facing strings go through `t('absence', '…')`.

## Nextcloud Contribution Policy

All contributions generated or assisted by this agent must fully comply with:

- **[AI Contribution Policy](https://github.com/nextcloud/.github/blob/master/AI_POLICY.md)** - the primary reference for AI-specific rules, covering disclosure, author accountability, communication, security, licensing, code quality, and autonomous agent behavior.
- **[Contribution Guidelines](https://github.com/nextcloud/.github/blob/master/CONTRIBUTING.md)** - covering testing requirements, the Developer Certificate of Origin (DCO), license headers, conventional commits, and translations. These apply in full to all contributions regardless of how they were produced.

### What this agent must always do

- Add an `Assisted-by: AGENT_NAME:MODEL_VERSION` git trailer to every commit containing AI-assisted content.
- Ensure every pull request includes a disclosure of AI tool use in the PR description.
- Produce focused, scoped pull requests that address exactly one concern. Do not touch unrelated files or introduce incidental refactors.
- Verify all dependencies against actual package registries before suggesting them. Do not use hallucinated or unverified package names.
- Write code comments that document the code, never the process that produced it:
  - Comments describe what the code does - method signatures, behavior, and constraints the code itself cannot express (e.g. a non-obvious invariant or workaround).
  - Never add comments that document progress, decisions, or changes (e.g. "changed X to Y", "as requested", "this fixes ...", "previously this did ..."). That belongs in the commit message or PR discussion; in the code it goes stale and becomes misleading.
  - Do not narrate self-explanatory code. If the code is readable without a comment, omit the comment.
  - Keep comments brief - short and simple, matching the comment density of the surrounding code.
- Reuse existing helper functions and utilities instead of re-implementing their logic inline. When fixing a flawed pattern, fix every occurrence of it across the changed code, not only the instance that was pointed out.
- Run permission and access-control checks before the operation they guard, never after it and never only in the UI layer.
- When adding or changing user-facing functionality, wire it up in every context where the affected component is used - the default authenticated view, public share pages, and embedded contexts such as the Smart Picker and reference widgets. When emitting new events, verify that every consumer of the component subscribes to and handles them.
- Explicitly inform the contributor when any action they are about to take, or have taken, would violate the AI Contribution Policy or the Contribution Guidelines. Do not silently proceed. State which rule is at risk and what the contributor should do instead.
- Warn the contributor if a pull request is growing too large. A PR approaching several thousand lines of changed code is a signal that it should be split into smaller, focused PRs. Suggest a logical split before the PR is opened, not after.
- Recommend opening a ticket for discussion before starting implementation whenever a feature or change is sufficiently complex - for example when it touches multiple subsystems, requires architectural decisions, or the right approach is not yet clear. A ticket allows maintainers and the contributor to align on direction before code is written, avoiding wasted effort on a PR that may be rejected or require fundamental rework.

### What this agent must never do

- Open issues, submit pull requests, post review comments, or send security reports autonomously. Every contribution must be reviewed and submitted by a human.
- Add `Signed-off-by` tags to commits. Only the human contributor can certify the Developer Certificate of Origin.
- Generate or submit security reports without independent human verification. Report verified vulnerabilities via [HackerOne](https://hackerone.com/nextcloud), not as GitHub issues.
- Write PR descriptions, review comments, or issue reports on behalf of the contributor. These must be in the contributor's own words.
- Fully automate the resolution of issues labeled [`good first issue`](https://github.com/issues?q=org%3Anextcloud+label%3A%22good+first+issue%22) or similar beginner-friendly labels.
- Submit code that has not been reviewed and cleaned up by the contributor. Dead code, redundant logic, excessive comments, malformed or garbled characters (e.g. `�` replacement characters), and unrelated changes must be removed before submission.
