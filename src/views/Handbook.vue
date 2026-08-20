<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page handbook-page">
		<header class="page__header handbook-page__header">
			<h2 class="page__title">
				{{ showHr ? t('absence', 'HR handbook') : t('absence', 'Handbook') }}
			</h2>
			<NcButton variant="tertiary" @click="print">
				<template #icon>
					<Printer :size="20" />
				</template>
				{{ t('absence', 'Print') }}
			</NcButton>
		</header>

		<div class="handbook">
			<!-- ------------------------------ sidebar ------------------------------ -->
			<aside class="handbook__nav" :aria-label="t('absence', 'Handbook contents')">
				<div class="handbook__search">
					<Magnify :size="18" class="handbook__search-icon" />
					<input
						ref="search"
						v-model="query"
						type="search"
						class="handbook__search-input"
						:placeholder="t('absence', 'Search the handbook…')"
						:aria-label="t('absence', 'Search the handbook')"
						@keydown.esc="query = ''">
				</div>
				<nav class="handbook__toc">
					<button
						v-for="s in sections"
						v-show="matches(s.id)"
						:key="s.id"
						class="handbook__toclink"
						:class="{ 'handbook__toclink--active': activeId === s.id && !query }"
						@click="scrollTo(s.id)">
						{{ s.title }}
					</button>
				</nav>
				<p v-if="query && visibleCount === 0" class="handbook__noresults">
					{{ t('absence', 'Nothing matches “{query}”.', { query }) }}
				</p>
			</aside>

			<!-- ------------------------------ content ------------------------------ -->
			<div ref="content" class="handbook__content" @click="onContentClick">
				<p v-if="showHr" class="handbook__lead">
					{{ t('absence', 'The extended handbook for the HR role. The first chapters apply to everybody — the HR chapters follow at the end.') }}
				</p>
				<p v-else class="handbook__lead">
					{{ t('absence', 'Everything you need to book leave, and — if people report to you — to decide on theirs.') }}
				</p>

				<Callout type="tip" class="handbook__quickstart">
					<p><strong>{{ t('absence', 'In 30 seconds') }}</strong></p>
					<p>{{ t('absence', 'Apply from “New request” → your line manager approves (or, if they don’t, it escalates to HR) → the leave lands in your calendar and your balance updates. Everything reaches you as a notification and email, so you rarely need to open the app.') }}</p>
				</Callout>

				<!-- ==================== everybody ==================== -->
				<section :ref="setRef('basics')" class="handbook__section">
					<h3>🌴 {{ t('absence', 'The basics') }}</h3>
					<p>{{ t('absence', 'Absence manages the company\'s time off: you apply, your line manager approves, and HR keeps the books. Your line manager comes from the "manager" field on your account — there is no separate org chart to maintain.') }}</p>
					<p>{{ t('absence', 'Everything important also reaches you outside the app: approvals arrive as Nextcloud notifications and email, approved leave lands in your calendar, and your balance sits on the Nextcloud dashboard.') }}</p>
					<p>{{ t('absence', 'Three roles shape what you see. As an employee you book your own leave and track your balance. If people report to you, you additionally decide their requests and see your team\'s coverage and balances. HR sees the whole company, records sick leave and other absences, manages entitlements, and runs the reports. You may hold several of these at once — the sidebar simply shows more sections the more you can do.') }}</p>
					<figure class="handbook__figure">
						<img :src="img('dashboard-widget.png')" :alt="t('absence', 'The Absence widget on the Nextcloud dashboard')">
						<figcaption>{{ t('absence', 'The dashboard widget: your balance, your upcoming leave — and, for deciders, what awaits them.') }}</figcaption>
					</figure>
				</section>

				<section :ref="setRef('my-leave')" class="handbook__section">
					<h3>⚖️ {{ t('absence', 'My leave: your balance') }}</h3>
					<p>{{ t('absence', 'The My leave page shows one ring per leave type: how much of your yearly allowance is used, pending and still available. The breakdown next to it explains the number — base allowance, days carried over from last year, and any manual adjustment HR made (each with a written reason).') }}</p>
					<p>{{ t('absence', '"Pending" days are requests awaiting a decision: they are subtracted from what you can still book, so you never promise the same days twice. Below the rings you find monthly charts and your full request history.') }}</p>
					<p>{{ t('absence', 'Read the numbers as: available = entitlement − used − pending. "Used" is approved leave that has been booked; "remaining" is what is left once used is subtracted, before pending is taken off; "available" is what you can still safely request today. Leave types that do not count against an allowance (unpaid or special leave, for instance) show usage but no ring — there is no ceiling to fill.') }}</p>
					<p>{{ t('absence', 'Each request in the history carries a coloured status: pending, approved, declined, cancelled, or awaiting a withdrawal decision. Click one to open its full detail and history.') }}</p>
					<p>{{ t('absence', 'At the top of the page, a countdown to your next approved break keeps the good news in view, and a "Your year so far" summary tallies the days you have taken, your longest break and your busiest month. When a request of yours is approved you will get a small celebration — a nudge to go and plan the trip.') }}</p>
					<figure class="handbook__figure handbook__figure--wide">
						<img :src="img('balance-card.webp')" :alt="t('absence', 'The annual leave balance ring with its breakdown')">
						<figcaption>{{ t('absence', 'The balance ring and its ledger: base, carry-over, adjustments — and what is left.') }}</figcaption>
					</figure>
				</section>

				<section :ref="setRef('requesting')" class="handbook__section">
					<h3>✈️ {{ t('absence', 'Requesting time off') }}</h3>
					<p>{{ t('absence', 'Click "New request", pick a leave type and the first and last day (inclusive). The rest fills itself in:') }}</p>
					<ul>
						<li>{{ t('absence', 'Working days are prefilled from your working weekdays and public holidays — but it is only an estimate. Always check the number and adjust it manually if it is off; your manager verifies it when approving.') }}</li>
						<li>{{ t('absence', 'The balance preview shows what the request would leave you with before you send it. Going past your balance warns you but never blocks — HR may still approve it.') }}</li>
						<li>{{ t('absence', 'If colleagues are already off during those dates, the dialog names them while you can still cheaply pick different days.') }}</li>
						<li>{{ t('absence', 'Some leave types ask for a replacement: a colleague who covers your duties. They are notified once the leave is approved — they see your dates, never your reason.') }}</li>
					</ul>
					<p>{{ t('absence', 'After you submit, your manager gets a notification with Approve and Decline right on it. If they sit on it too long, the request is automatically escalated to HR, so nothing gets stuck — including when you have no manager assigned at all, or when your manager is on leave themselves.') }}</p>
					<Callout type="note">
						<p>{{ t('absence', 'You cannot book two leaves that overlap the same day, and a leave lying entirely in the past is refused (only HR may record one after the fact). The working-day count may use halves for a half day, but never more days than the calendar range contains.') }}</p>
					</Callout>
					<figure class="handbook__figure">
						<img :src="img('request-form.webp')" :alt="t('absence', 'A walkthrough of filling in the request form: leave type, dates, the working-day estimate, the balance preview and the coverage warning')">
						<figcaption>{{ t('absence', 'Filling it in: pick a type and a range, and the working-day estimate, live balance preview and coverage warning fill themselves in — check the number before you send.') }}</figcaption>
					</figure>
				</section>

				<section :ref="setRef('changing')" class="handbook__section">
					<h3>↩️ {{ t('absence', 'Changing or cancelling') }}</h3>
					<ul>
						<li>{{ t('absence', 'A pending request can be edited or cancelled outright — the decider is told the request changed.') }}</li>
						<li>{{ t('absence', 'Editing approved leave creates a new request that needs approval again; the original stays in force until the edit is decided.') }}</li>
						<li>{{ t('absence', 'Cancelling approved leave asks your manager first ("withdrawal"), because they planned around it. If they decline the withdrawal, your leave simply stands.') }}</li>
						<li>{{ t('absence', 'Once a request is declined, cancelled or its leave is over, it is closed and can no longer be changed — the history stays as the record of what happened.') }}</li>
					</ul>
					<p>{{ t('absence', 'A withdrawal request and an edit cannot both be in flight on the same approved leave at once; decide or cancel the first before starting the second.') }}</p>
				</section>

				<section :ref="setRef('settings')" class="handbook__section">
					<h3>⚙️ {{ t('absence', 'Your settings') }}</h3>
					<p>{{ t('absence', 'Under Personal settings → Availability you set your working weekdays and your country/region for public holidays. Both only feed the working-day prefill on new requests — every prefilled value stays editable on the request itself.') }}</p>
					<p>{{ t('absence', 'Approved leave is written to your personal calendar, and to the shared team calendar as a neutral "Absent" (unless your admin chose otherwise). Colleagues never see why you are away — reasons are between you, your manager and HR.') }}</p>
					<figure class="handbook__figure handbook__figure--wide">
						<img :src="img('personal-settings.png')" :alt="t('absence', 'The Absence section on the Availability settings page')">
						<figcaption>{{ t('absence', 'Working days and holiday region live on the Availability page and only feed the prefill.') }}</figcaption>
					</figure>
				</section>

				<!-- ==================== team leads ==================== -->
				<section :ref="setRef('leads')" class="handbook__section">
					<h3>✅ {{ t('absence', 'For team leads: deciding requests') }}</h3>
					<p>{{ t('absence', 'Requests from your reports arrive as notifications — the common case is one click on Approve, without opening the app. Declining always requires a written reason.') }}</p>
					<p>{{ t('absence', 'The Approvals page lists everything awaiting you, including withdrawal requests. Opening a request shows the whole picture:') }}</p>
					<ul>
						<li>{{ t('absence', 'Coverage: who else on the team is off during those dates, and a warning when approving would take the team to the configured limit.') }}</li>
						<li>{{ t('absence', 'A short-notice flag when the leave starts sooner than the notice period your company expects.') }}</li>
						<li>{{ t('absence', 'The employee\'s balance for that leave type, so you never approve blind.') }}</li>
						<li>{{ t('absence', 'The reason, the comment thread, and the full history of every change and decision.') }}</li>
					</ul>
					<figure class="handbook__figure">
						<img :src="img('request-detail.webp')" :alt="t('absence', 'Opening a request in the sidebar and switching from Details to the Coverage tab')">
						<figcaption>{{ t('absence', 'One request, end to end: the stepper, balance, reason and decision on Details — then the Coverage tab, showing who else is off and whether approving would hit the team limit.') }}</figcaption>
					</figure>
					<Callout type="warning">
						<p>{{ t('absence', 'Verify the working-day count when approving — it is entered by hand and prefills are only estimates.') }}</p>
					</Callout>
					<p>{{ t('absence', 'Mind the clock: requests you leave undecided are first reminded, then escalated to HR after the configured number of working days. A short-notice request (starting sooner than the expected notice period) is flagged so you can prioritise it.') }}</p>
					<p>{{ t('absence', 'The comment thread on a request reaches the employee and, once escalated, HR — use it to ask a question ("can you move this a week?") without declining outright. Approving an edit to already-approved leave automatically retires the original, so the balance is never charged twice.') }}</p>
				</section>

				<section :ref="setRef('team')" class="handbook__section">
					<h3>👥 {{ t('absence', 'For team leads: the Team page') }}</h3>
					<p>{{ t('absence', 'The Team page shows a month timeline of your direct reports — approved leave as solid pills, pending as hatched — and below it their balances for the year: entitlement, used, pending and what is still free to book. That is the view to have open when somebody asks "can I take that week?".') }}</p>
					<figure class="handbook__figure handbook__figure--wide">
						<img :src="img('team-page.webp')" :alt="t('absence', 'The team timeline with the balances table below it')">
						<figcaption>{{ t('absence', 'The Team page: the month at a glance, balances underneath.') }}</figcaption>
					</figure>
				</section>

				<section :ref="setRef('privacy')" class="handbook__section">
					<h3>🔒 {{ t('absence', 'Who sees what') }}</h3>
					<Callout type="privacy">
						<p>{{ t('absence', 'Your leave type and your reason are private. Colleagues only ever see that you are absent; your manager and HR see the details; a nominated replacement sees only your dates.') }}</p>
					</Callout>
					<ul>
						<li>{{ t('absence', 'Colleagues see that you are absent, never the leave type or your reason (unless the admin deliberately switched the shared calendar to reveal types).') }}</li>
						<li>{{ t('absence', 'Your manager and HR see your requests, reasons and balances; a nominated replacement sees only your dates.') }}</li>
						<li>{{ t('absence', 'Certain sensitive categories recorded by HR (for example maternity leave) are visible to HR alone — everyone else, including yourself in this app, sees a neutral "Absent".') }}</li>
						<li>{{ t('absence', 'Files you attach to a request — a doctor\'s note, say — are visible to HR and you alone. Your manager never sees them. Attaching works from the request\'s detail view, including on sick leave HR recorded for you.') }}</li>
						<li>{{ t('absence', 'Every decision is written to the request\'s own history, so how a request was handled is always on the record.') }}</li>
					</ul>
				</section>

				<!-- ==================== HR ==================== -->
				<template v-if="showHr">
					<section :ref="setRef('hr-role')" class="handbook__section handbook__section--hr">
						<h3>🗄️ {{ t('absence', 'HR: your role') }}</h3>
						<p>{{ t('absence', 'HR sees the whole company and can correct anything — with every correction on the record. You can approve, decline, edit and cancel any request regardless of its state, and every HR override is written to the history and the audit log.') }}</p>
						<p>{{ t('absence', 'You are also the safety net: requests whose manager does not act, requests from employees without a manager, and requests whose manager is currently on leave themselves all land in your escalated queue (the counter on "Who\'s off" and the dashboard widget).') }}</p>
						<p>{{ t('absence', 'Concretely, HR can record and correct any absence (dates, type, working days), approve or decline on a manager\'s behalf, force-cancel approved leave without the withdrawal step, adjust anyone\'s entitlement, run every report and export, and act on a data-deletion request. Each of these leaves a trail — the point is that HR can unblock anything, never that anything happens quietly.') }}</p>
					</section>

					<section :ref="setRef('hr-record')" class="handbook__section handbook__section--hr">
						<h3>🤒 {{ t('absence', 'Recording absences') }}</h3>
						<p>{{ t('absence', 'Record absence books leave on an employee\'s behalf, straight to approved, with no approval step — sick leave being the everyday case. The employee, their manager and any replacement are notified.') }}</p>
						<p>{{ t('absence', 'Picking "Sick leave" reveals a Category selector. General sick leave is the default; the confidential categories are:') }}</p>
						<ul>
							<li>{{ t('absence', 'Child sick leave — an employee off to care for a sick child (in Germany, the statutory Kind-krank days).') }}</li>
							<li>{{ t('absence', 'Maternity leave — the protection period around a birth.') }}</li>
							<li>{{ t('absence', 'Parental leave — longer leave to raise a child, often months.') }}</li>
							<li>{{ t('absence', 'Medical work prohibition — a doctor\'s order barring the employee from working.') }}</li>
							<li>{{ t('absence', 'Doctor\'s note — a recorded sick note.') }}</li>
						</ul>
						<Callout type="privacy">
							<p>{{ t('absence', 'The category is strictly confidential: only HR ever sees which one it is. Everyone else — the line manager and the employee\'s own views, calendars and timelines — sees a neutral "Absent" with just the dates. The category names never even reach a non-HR browser.') }}</p>
						</Callout>
						<p>{{ t('absence', 'Recording for someone else never demands a replacement — you are stating a fact, often after the event.') }}</p>
						<p>{{ t('absence', 'Annual leave offers a "Disability-related" tick for the additional statutory entitlement. Like the sick-leave categories, only HR can set and see it — it never appears in the request history or to the employee.') }}</p>
						<figure class="handbook__figure">
							<img :src="img('record-absence-categories.webp')" :alt="t('absence', 'The record absence dialog with the confidential category dropdown open')">
							<figcaption>{{ t('absence', 'Sick leave opens a Category selector — general, child sick, maternity, parental, work prohibition, doctor\'s note. Only HR ever sees the choice.') }}</figcaption>
						</figure>
					</section>

					<section :ref="setRef('hr-absences')" class="handbook__section handbook__section--hr">
						<h3>🗂️ {{ t('absence', 'Managing absences') }}</h3>
						<p>{{ t('absence', 'The Absences page lists every record, filterable by employee, type, status and year. Opening one lets you fix the dates, type or working days, or cancel it. Cancelling keeps the record and its history for the audit trail — there is deliberately no delete.') }}</p>
						<figure class="handbook__figure handbook__figure--wide">
							<img :src="img('hr-absences.webp')" :alt="t('absence', 'The company-wide absences list with filters and status chips')">
							<figcaption>{{ t('absence', 'Every absence in one list — every status, every filter.') }}</figcaption>
						</figure>
					</section>

					<section :ref="setRef('hr-balances')" class="handbook__section handbook__section--hr">
						<h3>⚖️ {{ t('absence', 'Balances & entitlements') }}</h3>
						<p>{{ t('absence', 'Balances shows entitlement, used, pending, remaining and available per employee, year and type. Set entitlements individually or in bulk for a whole group.') }}</p>
						<p>{{ t('absence', 'Every entitlement is a base allowance plus an explicit adjustment with a mandatory note — "why does this person have 27 days?" always has a written answer. The pencil on a row opens the editor together with the change history.') }}</p>
						<p>{{ t('absence', 'Carry-over into the new year is computed automatically by the rollover job, following the policy, cap and expiry date from the admin settings.') }}</p>
						<p>{{ t('absence', 'Adjustments are entered as deltas: "+2 for the wedding", later "−2, booked in error" — the two cancel back to the original. The change history under each entitlement records who changed which figure, from what to what, and the note they gave.') }}</p>
						<figure class="handbook__figure handbook__figure--wide">
							<img :src="img('edit-entitlement.png')" :alt="t('absence', 'The entitlement editor with its change history')">
							<figcaption>{{ t('absence', 'The entitlement editor: base days, an adjustment with its mandatory note, and the change history.') }}</figcaption>
						</figure>
					</section>

					<section :ref="setRef('hr-reports')" class="handbook__section handbook__section--hr">
						<h3>📊 {{ t('absence', 'Statistics & reports') }}</h3>
						<ul>
							<li>{{ t('absence', 'Statistics: approved leave days, the average sick days per employee over the calendar year, the busiest month, days by leave type — and the "most vacation still to take" list, ranking who has the most unplanned days left so you can nudge them before the year ends.') }}</li>
							<li>{{ t('absence', 'Sick leave: employees ranked by days lost in a year, with episode counts — the type picker also covers the confidential categories.') }}</li>
							<li>{{ t('absence', 'Who\'s off: the company-wide timeline; every pill opens the record behind it.') }}</li>
							<li>{{ t('absence', 'Exports: leave requests and balances as CSV, with date-range and group filters, for payroll or an external HR system.') }}</li>
						</ul>
						<figure class="handbook__figure handbook__figure--wide">
							<img :src="img('hr-statistics.png')" :alt="t('absence', 'The statistics page with tiles, charts and the vacation-left list')">
							<figcaption>{{ t('absence', 'Statistics: the sick-day average, the trend — and who still has the most vacation to take.') }}</figcaption>
						</figure>
						<figure class="handbook__figure handbook__figure--wide">
							<img :src="img('hr-whos-off.png')" :alt="t('absence', 'The company-wide who\'s-off timeline')">
							<figcaption>{{ t('absence', 'Who\'s off, company-wide — each pill opens the record behind it.') }}</figcaption>
						</figure>
						<figure class="handbook__figure handbook__figure--wide">
							<img :src="img('hr-sick-leave.png')" :alt="t('absence', 'The sick-leave report ranking employees by days lost')">
							<figcaption>{{ t('absence', 'The sick-leave report: days lost per employee, with a type picker covering the confidential categories.') }}</figcaption>
						</figure>
					</section>

					<section :ref="setRef('hr-insights')" class="handbook__section handbook__section--hr">
						<h3>💡 {{ t('absence', 'Insights') }}</h3>
						<p>{{ t('absence', 'Where the Statistics tab describes what happened, Insights is diagnostic: it points at things worth acting on. Pick a year at the top; everything below recomputes for it.') }}</p>
						<ul>
							<li>{{ t('absence', 'Approval health — the median (and 95th-percentile) time from request to decision, with a per-manager table. It counts only leave that actually went to a manager, so HR-recorded absences do not flatter the figure, and it surfaces who is holding requests up and whose queue keeps escalating.') }}</li>
							<li>{{ t('absence', 'Bradford Factor — recorded sick leave scored as spells² × days, the long-standing HR measure that weights frequent short absences far more heavily than a single long illness. It is a prompt for a supportive conversation, not a verdict, and the colour turns amber then red at the usual review thresholds.') }}</li>
							<li>{{ t('absence', 'Leave utilisation — how much of their annual entitlement people actually take, per team, plus a watchlist of anyone who has not been off in a while. Persistently low use is an early burnout signal, which the headline totals hide.') }}</li>
							<li>{{ t('absence', 'Leave liability — the accrued-but-untaken annual leave carried on the books, per team, and the carry-over about to expire on the configured date if nobody uses it first.') }}</li>
						</ul>
						<p>{{ t('absence', 'Like every HR screen, Insights is HR-only: it names people and includes health-adjacent figures, so a line manager never sees it. Teams are grouped by the manager each person reports to; people with no manager form their own group.') }}</p>
						<figure class="handbook__figure handbook__figure--wide">
							<img :src="img('hr-insights.webp')" :alt="t('absence', 'The Insights tab with approval health, the Bradford Factor, utilisation and liability')">
							<figcaption>{{ t('absence', 'Insights: approval turnaround, the Bradford Factor, leave utilisation with a well-being watchlist, and the outstanding leave liability.') }}</figcaption>
						</figure>
					</section>

					<section :ref="setRef('hr-audit')" class="handbook__section handbook__section--hr">
						<h3>🧾 {{ t('absence', 'The audit trail') }}</h3>
						<p>{{ t('absence', 'Every important action is recorded three times: in the request\'s own history timeline (what the employee, manager and HR see), in the Nextcloud Activity feed (scoped to who may see that request), and as a structured entry in the server log written regardless of the instance log level, so operators keep an always-on audit trail. What people actually wrote — reasons, decision comments — is part of the record.') }}</p>
						<p>{{ t('absence', 'When an account is deleted, that person\'s leave, entitlements, attachments and the history naming them are purged; the aggregate figures behind the company statistics stay. Confidential categories and the disability flag are never written into the manager-visible history in the first place.') }}</p>
					</section>

					<section :ref="setRef('hr-setup')" class="handbook__section handbook__section--hr">
						<h3>🔧 {{ t('absence', 'Configuration to know about') }}</h3>
						<p>{{ t('absence', 'Administration settings → Absence holds the knobs your admin controls: the HR group, the optional employees group (recommended on large instances — it also keeps service accounts out of every report), the default entitlement, escalation and reminder windows, carry-over policy, the coverage threshold, the expected notice period, and the calendar integration including whether the shared calendar reveals leave types. Leave types themselves — including their colours, flags and confidential categories — are managed through the leave-type settings.') }}</p>
					</section>
				</template>

				<!-- ==================== FAQ ==================== -->
				<section :ref="setRef('faq')" class="handbook__section handbook__faq">
					<h3>❓ {{ t('absence', 'Frequently asked') }}</h3>
					<details class="handbook__qa">
						<summary>{{ t('absence', 'How many days do I have left?') }}</summary>
						<p>{{ t('absence', 'Open My leave: the annual-leave ring shows “available”, which is your entitlement minus what you have used and what is still pending. The dashboard widget shows the same figure without opening the app.') }}</p>
					</details>
					<details class="handbook__qa">
						<summary>{{ t('absence', 'Can I cancel leave I already booked?') }}</summary>
						<p>{{ t('absence', 'Yes. For a pending request, cancel it outright. For approved leave, cancelling asks your manager first (a “withdrawal”), because they planned around it — if they decline, your leave simply stands.') }}</p>
					</details>
					<details class="handbook__qa">
						<summary>{{ t('absence', 'Who can see why I am off?') }}</summary>
						<p>{{ t('absence', 'Colleagues only ever see that you are absent. Your manager and HR see the leave type and your reason; a nominated replacement sees only your dates. Sensitive categories HR records (such as maternity) are visible to HR alone.') }}</p>
					</details>
					<details class="handbook__qa">
						<summary>{{ t('absence', 'My manager is away — will my request get stuck?') }}</summary>
						<p>{{ t('absence', 'No. If your manager does not act in time, is on leave themselves, or you have no manager assigned, the request is automatically escalated to HR so it still gets a decision.') }}</p>
					</details>
					<details class="handbook__qa">
						<summary>{{ t('absence', 'The working-day count looks wrong — can I change it?') }}</summary>
						<p>{{ t('absence', 'Yes. The number is prefilled from your working days and public holidays, but it is only an estimate and stays editable. Adjust it before you submit; your manager verifies it when approving.') }}</p>
					</details>
					<template v-if="showHr">
						<details class="handbook__qa">
							<summary>{{ t('absence', 'How do I record sick leave for someone?') }}</summary>
							<p>{{ t('absence', 'Use “Record absence”: choose the employee and “Sick leave”. It is booked straight to approved with no approval step, and the confidential category you pick is visible to HR alone.') }}</p>
						</details>
						<details class="handbook__qa">
							<summary>{{ t('absence', 'Someone left the company — is their data removed?') }}</summary>
							<p>{{ t('absence', 'When the account is deleted, that person’s leave, entitlements, attachments and the history naming them are purged. Only the aggregate figures behind the company statistics remain.') }}</p>
						</details>
					</template>
				</section>
			</div>
		</div>

		<!-- ------------------------------ lightbox ------------------------------ -->
		<div
			v-if="lightbox"
			class="handbook__lightbox"
			role="dialog"
			:aria-label="t('absence', 'Enlarged illustration')"
			@click="lightbox = null">
			<img :src="lightbox" :alt="lightboxAlt">
			<button class="handbook__lightbox-close" :aria-label="t('absence', 'Close')">
				<Close :size="28" />
			</button>
		</div>
	</div>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { imagePath } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import Close from 'vue-material-design-icons/Close.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Printer from 'vue-material-design-icons/Printer.vue'
import Callout from '../components/Callout.vue'
import { store } from '../store.js'

export default {
	name: 'Handbook',
	components: { NcButton, Callout, Close, Magnify, Printer },

	props: {
		/** Render the extended HR chapters (the /hr/handbook route). */
		hr: { type: Boolean, default: false },
	},

	data() {
		return {
			store,
			sectionRefs: {},
			/** Full lower-cased text of each section, for search (built after mount). */
			sectionText: {},
			query: '',
			activeId: '',
			lightbox: null,
			lightboxAlt: '',
			observer: null,
		}
	},

	computed: {
		showHr() {
			return this.hr && store.session.isHr
		},

		sections() {
			const base = [
				{ id: 'basics', title: t('absence', 'The basics') },
				{ id: 'my-leave', title: t('absence', 'My leave: your balance') },
				{ id: 'requesting', title: t('absence', 'Requesting time off') },
				{ id: 'changing', title: t('absence', 'Changing or cancelling') },
				{ id: 'settings', title: t('absence', 'Your settings') },
				{ id: 'leads', title: t('absence', 'For team leads: deciding requests') },
				{ id: 'team', title: t('absence', 'For team leads: the Team page') },
				{ id: 'privacy', title: t('absence', 'Who sees what') },
			]
			const hr = this.showHr
				? [
						{ id: 'hr-role', title: t('absence', 'HR: your role') },
						{ id: 'hr-record', title: t('absence', 'Recording absences') },
						{ id: 'hr-absences', title: t('absence', 'Managing absences') },
						{ id: 'hr-balances', title: t('absence', 'Balances & entitlements') },
						{ id: 'hr-reports', title: t('absence', 'Statistics & reports') },
						{ id: 'hr-insights', title: t('absence', 'Insights') },
						{ id: 'hr-audit', title: t('absence', 'The audit trail') },
						{ id: 'hr-setup', title: t('absence', 'Configuration to know about') },
					]
				: []
			return [...base, ...hr, { id: 'faq', title: t('absence', 'Frequently asked') }]
		},

		visibleCount() {
			return this.sections.filter((s) => this.matches(s.id)).length
		},
	},

	watch: {
		query() {
			this.applySearch()
		},
	},

	mounted() {
		// The HR route is documentation, not data — but a non-HR visitor should
		// land on the handbook that matches what they can actually see.
		if (this.hr && !store.session.isHr) {
			this.$router.replace({ name: 'handbook' })
			return
		}
		this.$nextTick(() => {
			this.indexSections()
			this.injectAnchors()
			this.setupScrollSpy()
			// Deep link: /handbook?s=requesting scrolls straight to that chapter.
			const target = this.$route.query.s
			if (target && this.sectionRefs[target]) {
				this.scrollTo(target)
			}
		})
		window.addEventListener('keydown', this.onKeydown)
	},

	beforeUnmount() {
		this.observer?.disconnect()
		this.revealObserver?.disconnect()
		window.removeEventListener('keydown', this.onKeydown)
	},

	methods: {
		t,

		/**
		 * Handbook illustrations ship with the app under img/handbook/.
		 *
		 * @param {string} name file name, e.g. 'request-form.webp'
		 * @return {string}
		 */
		img(name) {
			return imagePath('absence', 'handbook/' + name)
		},

		/**
		 * Ref-collector per section. Plain #anchors are unavailable under hash
		 * routing (the URL fragment is the route), so the TOC scrolls via refs.
		 *
		 * @param {string} id section id
		 * @return {Function}
		 */
		setRef(id) {
			return (el) => {
				if (el) {
					this.sectionRefs[id] = el
				}
			}
		},

		/** Cache each section's text so search can match the body, not just titles. */
		indexSections() {
			const text = {}
			for (const [id, el] of Object.entries(this.sectionRefs)) {
				text[id] = (el.textContent || '').toLowerCase()
			}
			this.sectionText = text
		},

		/**
		 * True when a section should show for the current query (title or body).
		 *
		 * @param {string} id section id
		 * @return {boolean}
		 */
		matches(id) {
			const q = this.query.trim().toLowerCase()
			if (!q) {
				return true
			}
			const section = this.sections.find((s) => s.id === id)
			const inTitle = section && section.title.toLowerCase().includes(q)
			return inTitle || (this.sectionText[id] || '').includes(q)
		},

		/** Show/hide the section blocks to mirror the filtered TOC. */
		applySearch() {
			for (const [id, el] of Object.entries(this.sectionRefs)) {
				el.classList.toggle('handbook__section--filtered', !this.matches(id))
			}
			const q = this.query.trim()
			// Jump to the first hit so a search always shows *something* useful.
			if (q) {
				const first = this.sections.find((s) => this.matches(s.id))
				if (first) {
					this.sectionRefs[first.id]?.scrollIntoView({ block: 'start' })
				}
			}
		},

		/** Add a hover "copy link" affordance to each chapter heading. */
		injectAnchors() {
			const route = this.showHr ? 'hr/handbook' : 'handbook'
			const bookmark = window.location.href.split('#')[0] + '#/' + route
			for (const [id, el] of Object.entries(this.sectionRefs)) {
				const h = el.querySelector('h3')
				if (!h || h.querySelector('.handbook__anchor')) {
					continue
				}
				const btn = document.createElement('button')
				btn.className = 'handbook__anchor'
				btn.type = 'button'
				btn.title = t('absence', 'Copy link to this section')
				btn.setAttribute('aria-label', btn.title)
				btn.textContent = '🔗'
				btn.addEventListener('click', () => {
					navigator.clipboard?.writeText(`${bookmark}?s=${id}`)
						.then(() => showSuccess(t('absence', 'Link copied')))
						.catch(() => {})
				})
				h.appendChild(btn)
			}
		},

		/**
		 * Two observers over the sections: one reveals each section as it enters
		 * the viewport (a generous band, so above-the-fold sections reveal at once),
		 * the other tracks which section is nearest the top for the active TOC entry
		 * (a thin band). Sharing one observer conflated the two and left sections
		 * outside the thin band permanently hidden.
		 */
		setupScrollSpy() {
			this.revealObserver = new IntersectionObserver((entries) => {
				for (const e of entries) {
					if (e.isIntersecting) {
						e.target.classList.add('handbook__section--revealed')
						this.revealObserver.unobserve(e.target)
					}
				}
			}, { rootMargin: '0px 0px -8% 0px', threshold: 0 })
			this.observer = new IntersectionObserver((entries) => {
				for (const e of entries) {
					if (e.isIntersecting) {
						const hit = Object.entries(this.sectionRefs).find(([, el]) => el === e.target)
						if (hit) {
							this.activeId = hit[0]
						}
					}
				}
			}, { rootMargin: '-8% 0px -80% 0px', threshold: 0 })
			for (const el of Object.values(this.sectionRefs)) {
				el.classList.add('handbook__section--reveal')
				this.revealObserver.observe(el)
				this.observer.observe(el)
			}
		},

		/**
		 * @param {string} id section id from the TOC
		 */
		scrollTo(id) {
			this.sectionRefs[id]?.scrollIntoView({ behavior: 'smooth', block: 'start' })
		},

		/**
		 * Open the lightbox when a figure image is clicked.
		 *
		 * @param e
		 */
		onContentClick(e) {
			const img = e.target.closest?.('.handbook__figure img')
			if (img) {
				this.lightbox = img.currentSrc || img.src
				this.lightboxAlt = img.alt || ''
			}
		},

		onKeydown(e) {
			if (e.key === 'Escape' && this.lightbox) {
				this.lightbox = null
				return
			}
			// "/" focuses search, the way many docs and apps do.
			if (e.key === '/' && !/^(INPUT|TEXTAREA)$/.test(document.activeElement?.tagName)) {
				e.preventDefault()
				this.$refs.search?.focus()
			}
		},

		print() {
			window.print()
		},
	},
}
</script>

<style scoped lang="scss">
.handbook-page {
	max-width: 1240px;

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
	}
}

.handbook {
	display: flex;
	align-items: flex-start;
	gap: 28px;

	// ------------------------------ sidebar ------------------------------
	&__nav {
		position: sticky;
		top: 8px;
		flex: 0 0 232px;
		max-height: calc(100vh - 120px);
		overflow-y: auto;
		padding-bottom: 8px;
	}

	&__search {
		position: relative;
		margin-bottom: 12px;
	}

	&__search-icon {
		position: absolute;
		inset-inline-start: 10px;
		top: 50%;
		transform: translateY(-50%);
		opacity: 0.6;
		pointer-events: none;
	}

	&__search-input {
		width: 100%;
		padding: 7px 12px 7px 36px;
		border: 2px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
		color: var(--color-main-text);

		&:focus,
		&:focus-visible {
			border-color: var(--color-primary-element);
			outline: none;
		}
	}

	&__toc {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__toclink {
		border: none;
		border-inline-start: 3px solid transparent;
		border-radius: 0 var(--border-radius) var(--border-radius) 0;
		background: transparent;
		color: var(--color-text-maxcontrast);
		padding: 6px 12px;
		font-size: 0.9rem;
		text-align: start;
		cursor: pointer;

		&:hover,
		&:focus-visible {
			background: var(--color-background-hover);
			color: var(--color-main-text);
		}

		&--active {
			border-inline-start-color: var(--color-primary-element);
			background: var(--color-primary-element-light);
			color: var(--color-main-text);
			font-weight: 600;
		}
	}

	&__noresults {
		margin: 8px 12px;
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
	}

	// ------------------------------ content ------------------------------
	&__content {
		flex: 1 1 auto;
		min-width: 0;
		max-width: 1040px;
	}

	&__lead {
		margin: 0 0 12px;
		max-width: 720px;
		color: var(--color-text-maxcontrast);
	}

	&__quickstart {
		margin-top: 0;
	}

	&__figure {
		margin: 16px 0 6px;

		img {
			display: block;
			max-width: 100%;
			max-height: 620px;
			width: auto;
			height: auto;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius-large);
			box-shadow: 0 1px 4px var(--color-box-shadow);
			background: var(--color-main-background);
			margin-inline: auto;
			cursor: zoom-in;
		}

		figcaption {
			margin-top: 6px;
			max-width: 720px;
			font-size: 0.8rem;
			color: var(--color-text-maxcontrast);
			margin-inline: auto;
		}

		&--wide {
			img {
				width: 100%;
				max-height: none;
			}

			figcaption {
				max-width: none;
			}
		}
	}

	&__section {
		margin-top: 28px;
		scroll-margin-top: 16px;

		h3 {
			display: flex;
			align-items: center;
			gap: 8px;
			margin: 0 0 8px;
			max-width: 720px;
		}

		p,
		li {
			line-height: 1.55;
		}

		> p {
			max-width: 720px;
		}

		ul {
			padding-inline-start: 20px;
			max-width: 720px;
		}

		&--hr {
			border-inline-start: 3px solid var(--color-primary-element);
			padding-inline-start: 14px;
		}

		// Hidden by the search filter.
		&--filtered {
			display: none;
		}

		// Subtle reveal as a section scrolls into view.
		&--reveal {
			opacity: 0;
			transform: translateY(10px);
			transition: opacity 400ms ease, transform 400ms ease;
		}

		&--revealed {
			opacity: 1;
			transform: none;
		}
	}

	// Injected copy-link button on each heading (created in JS).
	:deep(.handbook__anchor) {
		border: none;
		background: transparent;
		cursor: pointer;
		font-size: 0.85rem;
		opacity: 0;
		transition: opacity 150ms ease;
		padding: 0 4px;
		line-height: 1;
	}

	&__section h3:hover :deep(.handbook__anchor),
	:deep(.handbook__anchor:focus-visible) {
		opacity: 0.7;
	}

	// FAQ accordions.
	&__qa {
		max-width: 720px;
		border-bottom: 1px solid var(--color-border);
		padding: 4px 0;

		summary {
			cursor: pointer;
			padding: 8px 4px;
			font-weight: 600;
			list-style-position: inside;
		}

		p {
			margin: 4px 4px 12px;
			max-width: none;
			color: var(--color-text-maxcontrast);
		}
	}

	// ------------------------------ lightbox ------------------------------
	&__lightbox {
		position: fixed;
		inset: 0;
		z-index: 10000;
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 3vmin;
		background: rgba(0, 0, 0, 0.72);
		cursor: zoom-out;

		img {
			max-width: 96vw;
			max-height: 94vh;
			border-radius: var(--border-radius-large);
			box-shadow: 0 12px 48px rgba(0, 0, 0, 0.5);
		}
	}

	&__lightbox-close {
		position: absolute;
		top: 16px;
		inset-inline-end: 16px;
		border: none;
		border-radius: 50%;
		width: 44px;
		height: 44px;
		display: flex;
		align-items: center;
		justify-content: center;
		background: rgba(255, 255, 255, 0.15);
		color: #fff;
		cursor: pointer;

		&:hover { background: rgba(255, 255, 255, 0.28); }
	}
}

@media (max-width: 900px) {
	.handbook {
		flex-direction: column;
		gap: 12px;

		&__nav {
			position: static;
			max-height: none;
			flex-basis: auto;
			width: 100%;
		}

		&__toc {
			flex-direction: row;
			flex-wrap: wrap;
			gap: 6px;
		}

		&__toclink {
			border-inline-start: none;
			border-radius: var(--border-radius-pill);
			background: var(--color-background-hover);

			&--active {
				background: var(--color-primary-element-light);
			}
		}
	}
}

@media (prefers-reduced-motion: reduce) {
	.handbook__section--reveal {
		opacity: 1;
		transform: none;
		transition: none;
	}
}

@media print {
	.handbook__nav,
	.handbook-page__header :deep(button),
	:deep(.handbook__anchor) {
		display: none !important;
	}

	.handbook {
		display: block;
	}

	.handbook__section--reveal {
		opacity: 1 !important;
		transform: none !important;
	}

	.handbook__figure img {
		max-height: none;
		box-shadow: none;
	}

	.handbook__qa[open] p,
	.handbook__qa p {
		display: block;
	}
}
</style>
