<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page page--narrow page--roomy">
		<header class="page__header page__header--center">
			<h2 class="page__title">
				{{ t('absence', 'My leave') }}
			</h2>
			<NcButton variant="primary" @click="openNew">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('absence', 'New request') }}
			</NcButton>
		</header>

		<section v-if="nextBreak" class="hero" :style="{ '--accent': nextBreak.color }">
			<span class="hero__emoji" aria-hidden="true">{{ nextBreak.icon }}</span>
			<div class="hero__text">
				<span class="hero__eyebrow">{{ nextBreak.eyebrow }}</span>
				<!-- role="timer" is the ticking-value role, and it is silent by default —
				     an aria-live region here would read the countdown out every second. -->
				<strong
					class="hero__headline"
					:class="{ 'hero__headline--ticking': nextBreak.live }"
					:role="nextBreak.live ? 'timer' : undefined">{{ nextBreak.headline }}</strong>
				<span class="hero__sub">{{ nextBreak.sub }}</span>
			</div>
		</section>

		<section v-if="rings.length" class="overview">
			<BalanceCard v-for="row in rings" :key="row.typeId + '-' + row.year" :row="row" />
		</section>

		<YearInReview :year="year" />

		<section v-if="leaveByMonth || sickByMonth" class="charts">
			<div v-if="leaveByMonth" class="surface">
				<BarChart :title="t('absence', 'Leave taken by month ({year})', { year })" :data="leaveByMonth" />
			</div>
			<div v-if="sickByMonth" class="surface">
				<BarChart :title="t('absence', 'Sick days by month ({year})', { year })" :data="sickByMonth" />
			</div>
		</section>

		<section class="requests">
			<h3 class="requests__title">
				{{ t('absence', 'Requests') }}
			</h3>
			<SkeletonList v-if="requestsStore.loading" :rows="4" />
			<TransitionGroup
				v-else-if="requestsStore.requests.length"
				tag="ul"
				name="rli"
				class="requests__list">
				<RequestListItem
					v-for="r in requestsStore.requests"
					:key="r.id"
					:request="r"
					:active="requestsStore.selectedId === r.id"
					@select="requestsStore.select($event)" />
			</TransitionGroup>
			<NcEmptyContent
				v-else
				:name="t('absence', 'No leave requests yet')"
				:description="t('absence', 'Your leave requests will appear here once you submit one.')">
				<template #icon>
					<PalmIllustration />
				</template>
				<template #action>
					<NcButton variant="primary" @click="openNew">
						{{ t('absence', 'Request time off') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</section>
	</div>
</template>

<script setup lang="ts">
import type { LeaveTypeLook } from '../stores/leaveTypes.ts'

import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import Plus from 'vue-material-design-icons/Plus.vue'
import BalanceCard from '../components/BalanceCard.vue'
import BarChart from '../components/BarChart.vue'
import PalmIllustration from '../components/PalmIllustration.vue'
import RequestListItem from '../components/RequestListItem.vue'
import SkeletonList from '../components/SkeletonList.vue'
import YearInReview from '../components/YearInReview.vue'
import { useLeaveTypesStore } from '../stores/leaveTypes.ts'
import { useRequestsStore } from '../stores/requests.ts'
import { useSessionStore } from '../stores/session.ts'
import { addWorkingDaysByMonth, formatCountdown, formatRange, toIso } from '../utils/dates.ts'
import { openNewKey } from '../utils/injectionKeys.ts'
import { locale } from '../utils/locale.ts'

const props = withDefaults(defineProps<{
	id?: string | number | null
}>(), {
	id: null,
})
const requestsStore = useRequestsStore()
const leaveTypesStore = useLeaveTypesStore()
const sessionStore = useSessionStore()

/** The hero above the balances: the leave to look forward to, or the one going on */
interface NextBreak {
	icon: string
	color: string
	eyebrow: string
	headline: string
	sub: string
	/** Whether the headline counts down in seconds */
	live: boolean
}

/** One bar of a month chart */
interface MonthBar {
	label: string
	value: number
	color?: string
}

// Inside this window the hero counts down in seconds rather than whole days.
// Two days is roughly where "2 days to go" stops being the more useful sentence.
const LIVE_COUNTDOWN_MS = 48 * 60 * 60 * 1000

// Which of my own approvals have already been celebrated, so the confetti fires
// once when leave is approved — not again on every later visit, and never for the
// backlog that already existed the first time this browser ran the app.
const CELEBRATED_KEY = 'absence:celebrated'

const openNewRequest = inject(openNewKey)

// The clock the hero reads from. Kept in state rather than called inline so
// the countdown re-renders — and so a tab left open overnight still rolls
// its day count over at midnight instead of freezing on yesterday.
const now = ref(Date.now())
let clock: ReturnType<typeof setInterval> | undefined

const year = computed(() => new Date(now.value).getFullYear())

const rings = computed(() => requestsStore.balance.balances.filter((b) => b.year === year.value && b.countsAgainstBalance))

/** Approved counting leave (annual etc.) per month of the current year. */
const leaveByMonth = computed(() => monthChart((type) => type.countsAgainstBalance !== false && type.key !== 'sick', null))

/** Sick days per month of the current year, or null when the type is not configured. */
const sickByMonth = computed(() => {
	const sickType = leaveTypesStore.leaveTypes.find((type) => type.key === 'sick')
	if (!sickType) {
		return null
	}
	return monthChart((type) => type.key === 'sick', sickType.color)
})

/** The soonest upcoming (or ongoing) approved leave, as a motivating hero. */
const nextBreak = computed<NextBreak | null>(() => {
	const today = toIso(new Date(now.value))
	const approved = requestsStore.requests
		.filter((r) => r.status === 'APPROVED' && r.endDate >= today)
		.sort((a, b) => a.startDate.localeCompare(b.startDate))
	const r = approved[0]
	if (!r) {
		return null
	}
	const type = leaveTypesStore.leaveType(r.typeId)
	const range = formatRange(r.startDate, r.endDate)
	if (r.startDate <= today) {
		// The hero already renders the leave type's own icon next to this text, so
		// the palm that used to be baked into the string was a second, type-blind
		// one — it wished a holiday on whatever the absence actually was. Sick
		// leave is not something to enjoy, either.
		const headline = type.key === 'sick'
			? t('absence', 'Get well soon.')
			: t('absence', 'Enjoy your time off!')
		return {
			icon: type.icon,
			color: type.color,
			eyebrow: t('absence', 'You are off right now'),
			headline,
			sub: `${type.label} · ${range}`,
			live: false,
		}
	}
	const remaining = new Date(r.startDate + 'T00:00:00').getTime() - now.value
	// The last two days are the ones worth watching, and "1 day to go" is a
	// poor description of an afternoon — so the final stretch ticks.
	if (remaining <= LIVE_COUNTDOWN_MS) {
		return {
			icon: type.icon,
			color: type.color,
			eyebrow: t('absence', 'Almost there'),
			headline: t('absence', '{countdown} to go', { countdown: formatCountdown(remaining) }),
			sub: `${type.label} · ${range}`,
			live: true,
		}
	}
	return {
		icon: type.icon,
		color: type.color,
		eyebrow: t('absence', 'Your next break'),
		headline: n('absence', '%n day to go', '%n days to go', Math.round(remaining / 86400000)),
		sub: `${type.label} · ${range}`,
		live: false,
	}
})

// Immediate, and watched: a link to /requests/:id followed while this page
// is already open changes the id without mounting the page again.
watch(() => props.id, (id) => {
	if (id) {
		requestsStore.select(Number(id))
	}
}, { immediate: true })

onMounted(() => {
	reload()
	window.addEventListener('absence:refresh', reload)
	clock = setInterval(tick, 1000)
})

onBeforeUnmount(() => {
	window.removeEventListener('absence:refresh', reload)
	clearInterval(clock)
})

/**
 * Advance the hero's clock. The interval runs every second but only commits a
 * new value when the rendered text can have changed — otherwise a page showing
 * "12 days to go" would re-render its whole request list once a second, all
 * year, to display the same sentence.
 */
function tick(): void {
	const current = Date.now()
	if (nextBreak.value?.live || current - now.value >= 60000) {
		now.value = current
	}
}

/**
 * BarChart data (12 months of the current year) from my approved requests
 * whose type matches. Months without leave stay at zero so the chart is
 * always visible.
 *
 * @param typeMatches which leave types to count
 * @param color the colour of the bars
 */
function monthChart(typeMatches: (type: LeaveTypeLook) => boolean, color: string | null): MonthBar[] {
	const buckets = new Array<number>(12).fill(0)
	for (const r of requestsStore.requests) {
		if (r.status !== 'APPROVED' || !typeMatches(leaveTypesStore.leaveType(r.typeId))) {
			continue
		}
		addWorkingDaysByMonth(buckets, r.startDate, r.endDate, r.workingDays, year.value)
	}
	return buckets.map((value, month) => ({
		label: new Date(year.value, month, 1).toLocaleDateString(locale(), { month: 'short' }),
		value: Math.round(value * 10) / 10,
		...(color ? { color } : {}),
	}))
}

/**
 * Open the form for a new request
 */
function openNew(): void {
	openNewRequest?.()
}

/**
 * Load my requests and balances, then celebrate what was approved meanwhile
 */
async function reload(): Promise<void> {
	await Promise.all([
		requestsStore.loadRequests({ scope: 'mine' }),
		// No year on purpose: the server then answers with every year, which the request form reads.
		requestsStore.loadMyBalance(),
	])
	celebrateNewApprovals()
}

/**
 * Fire a one-time celebration when one of my own leave requests has newly
 * been approved since the last visit. Sick leave and past-dated records are
 * excluded — this is for a trip to look forward to, not every status change.
 */
function celebrateNewApprovals(): void {
	// One list per account: several people can share a browser.
	const key = `${CELEBRATED_KEY}:${sessionStore.session.uid}`
	let raw: string | null
	try {
		raw = window.localStorage.getItem(key)
	} catch {
		return // no storage (private mode, etc.) — skip quietly.
	}
	const approvedIds = requestsStore.requests.filter((r) => r.status === 'APPROVED').map((r) => r.id)
	const persist = () => {
		try {
			window.localStorage.setItem(key, JSON.stringify(approvedIds))
		} catch { /* ignore */ }
	}
	// First run on this browser: adopt the existing approvals silently so the
	// backlog never sets off a burst — only future approvals celebrate.
	if (raw === null) {
		persist()
		return
	}
	let seen: Set<unknown>
	try {
		const stored: unknown = JSON.parse(raw)
		if (!Array.isArray(stored)) {
			throw new TypeError('The celebrated list is not a list')
		}
		seen = new Set<unknown>(stored)
	} catch {
		persist()
		return
	}
	const today = toIso(new Date())
	const fresh = requestsStore.requests.filter((r) => {
		if (r.status !== 'APPROVED' || seen.has(r.id) || r.endDate < today) {
			return false
		}
		// Only a genuine trip to look forward to — never sick leave, and never
		// a withheld confidential absence (its type is null on this view).
		const type = leaveTypesStore.leaveType(r.typeId)
		return type.countsAgainstBalance === true && type.key && type.key !== 'sick'
	})
	persist()
	const first = fresh[0]
	if (!first) {
		return
	}
	window.dispatchEvent(new CustomEvent('absence:celebrate'))
	const type = leaveTypesStore.leaveType(first.typeId)
	showSuccess(t('absence', 'Approved: {type}. Enjoy! ✈️', { type: type.label }))
}
</script>

<style scoped lang="scss">

.hero {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	border-radius: var(--border-radius-large, 12px);
	background: linear-gradient(135deg,
		color-mix(in srgb, var(--accent) 22%, var(--color-main-background)),
		color-mix(in srgb, var(--accent) 8%, var(--color-main-background)));
	border: 1px solid color-mix(in srgb, var(--accent) 30%, transparent);

	&__emoji {
		font-size: 2.4rem;
		line-height: 1;
	}

	&__text {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__eyebrow {
		font-size: 0.78rem;
		text-transform: uppercase;
		letter-spacing: 0.06em;
		color: var(--color-text-maxcontrast);
	}

	&__headline {
		font-size: 1.25rem;

		// Proportional digits make a ticking clock jitter as the glyph widths change.
		&--ticking {
			font-variant-numeric: tabular-nums;
			font-feature-settings: 'tnum';
		}
	}

	&__sub {
		font-size: 0.9rem;
		color: var(--color-text-maxcontrast);
	}
}

.overview {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.charts {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: calc(var(--default-grid-baseline, 4px) * 3);

}

.requests {
	&__title {
		margin: 0 0 12px;
		font-size: 1.1rem;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding: 0;
		margin: 0;
		list-style: none;
	}
}

// List enter/leave/move transitions.
.rli-enter-active,
.rli-leave-active {
	transition: opacity 250ms ease, transform 250ms ease;
}

.rli-enter-from {
	opacity: 0;
	transform: translateY(8px);
}

.rli-leave-to {
	opacity: 0;
	transform: translateX(-12px);
}

.rli-move {
	transition: transform 250ms ease;
}

@media (prefers-reduced-motion: reduce) {
	.rli-enter-active,
	.rli-leave-active,
	.rli-move { transition: none; }
}
</style>
