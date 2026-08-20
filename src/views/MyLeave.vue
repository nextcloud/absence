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
					:role="nextBreak.live ? 'timer' : null">{{ nextBreak.headline }}</strong>
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
			<SkeletonList v-if="store.loading" :rows="4" />
			<TransitionGroup
				v-else-if="store.requests.length"
				tag="ul"
				name="rli"
				class="requests__list">
				<RequestListItem
					v-for="r in store.requests"
					:key="r.id"
					:request="r"
					:active="store.selectedId === r.id"
					@select="store.select($event)" />
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

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import Plus from 'vue-material-design-icons/Plus.vue'
import BalanceCard from '../components/BalanceCard.vue'
import BarChart from '../components/BarChart.vue'
import PalmIllustration from '../components/PalmIllustration.vue'
import RequestListItem from '../components/RequestListItem.vue'
import SkeletonList from '../components/SkeletonList.vue'
import YearInReview from '../components/YearInReview.vue'
import { store } from '../store.js'
import { addWorkingDaysByMonth, formatCountdown, formatRange, toIso } from '../utils/dates.js'

// Inside this window the hero counts down in seconds rather than whole days.
// Two days is roughly where "2 days to go" stops being the more useful sentence.
const LIVE_COUNTDOWN_MS = 48 * 60 * 60 * 1000

// Which of my own approvals have already been celebrated, so the confetti fires
// once when leave is approved — not again on every later visit, and never for the
// backlog that already existed the first time this browser ran the app.
const CELEBRATED_KEY = 'absence:celebrated'

export default {
	name: 'MyLeave',
	components: { NcButton, NcEmptyContent, Plus, BalanceCard, BarChart, RequestListItem, SkeletonList, PalmIllustration, YearInReview },
	inject: ['absence:openNew'],
	props: {
		id: { type: [String, Number], default: null },
	},

	setup() {
		// Expose the module-level reactive store to the template (Options API).
		return { store }
	},

	data() {
		return {
			// The clock the hero reads from. Kept in state rather than called inline so
			// the countdown re-renders — and so a tab left open overnight still rolls
			// its day count over at midnight instead of freezing on yesterday.
			now: Date.now(),
			clock: null,
		}
	},

	computed: {
		year() {
			return new Date(this.now).getFullYear()
		},

		rings() {
			return store.balance.balances.filter((b) => b.year === this.year && b.countsAgainstBalance)
		},

		/** Approved counting leave (annual etc.) per month of the current year. */
		leaveByMonth() {
			return this.monthChart((type) => type.countsAgainstBalance !== false && type.key !== 'sick', null)
		},

		/** Sick days per month of the current year, or null when the type is not configured. */
		sickByMonth() {
			const sickType = store.leaveTypes.find((type) => type.key === 'sick')
			if (!sickType) {
				return null
			}
			return this.monthChart((type) => type.key === 'sick', sickType.color)
		},

		/** The soonest upcoming (or ongoing) approved leave, as a motivating hero. */
		nextBreak() {
			const today = toIso(new Date(this.now))
			const approved = store.requests
				.filter((r) => r.status === 'APPROVED' && r.endDate >= today)
				.sort((a, b) => a.startDate.localeCompare(b.startDate))
			if (!approved.length) {
				return null
			}
			const r = approved[0]
			const type = store.leaveType(r.typeId)
			const range = formatRange(r.startDate, r.endDate)
			if (r.startDate <= today) {
				// The hero already renders the leave type's own icon next to this text, so
				// the palm that used to be baked into the string was a second, type-blind
				// one — it wished a holiday on whatever the absence actually was. Sick
				// leave is not something to enjoy, either.
				const headline = type.key === 'sick'
					? t('absence', 'Get well soon.')
					: t('absence', 'Enjoy your {type}!', { type: type.label.toLowerCase() })
				return {
					icon: type.icon,
					color: type.color,
					eyebrow: t('absence', 'You are off right now'),
					headline,
					sub: range,
					live: false,
				}
			}
			const remaining = new Date(r.startDate + 'T00:00:00').getTime() - this.now
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
		},
	},

	mounted() {
		this.reload()
		window.addEventListener('absence:refresh', this.reload)
		if (this.id) {
			store.select(Number(this.id))
		}
		this.clock = setInterval(this.tick, 1000)
	},

	beforeUnmount() {
		window.removeEventListener('absence:refresh', this.reload)
		clearInterval(this.clock)
	},

	methods: {
		t,
		/**
		 * Advance the hero's clock. The interval runs every second but only commits a
		 * new value when the rendered text can have changed — otherwise a page showing
		 * "12 days to go" would re-render its whole request list once a second, all
		 * year, to display the same sentence.
		 */
		tick() {
			const now = Date.now()
			if (this.nextBreak?.live || now - this.now >= 60000) {
				this.now = now
			}
		},

		/**
		 * BarChart data (12 months of the current year) from my approved requests
		 * whose type matches. Months without leave stay at zero so the chart is
		 * always visible.
		 *
		 * @param typeMatches
		 * @param color
		 */
		monthChart(typeMatches, color) {
			const buckets = new Array(12).fill(0)
			for (const r of store.requests) {
				if (r.status !== 'APPROVED' || !typeMatches(store.leaveType(r.typeId))) {
					continue
				}
				addWorkingDaysByMonth(buckets, r.startDate, r.endDate, r.workingDays, this.year)
			}
			return buckets.map((value, month) => ({
				label: new Date(this.year, month, 1).toLocaleDateString(undefined, { month: 'short' }),
				value: Math.round(value * 10) / 10,
				...(color ? { color } : {}),
			}))
		},

		openNew() {
			this['absence:openNew']()
		},

		async reload() {
			await Promise.all([
				store.loadRequests({ scope: 'mine' }),
				store.loadMyBalance(),
			])
			this.celebrateNewApprovals()
		},

		/**
		 * Fire a one-time celebration when one of my own leave requests has newly
		 * been approved since the last visit. Sick leave and past-dated records are
		 * excluded — this is for a trip to look forward to, not every status change.
		 */
		celebrateNewApprovals() {
			let raw
			try {
				raw = window.localStorage.getItem(CELEBRATED_KEY)
			} catch {
				return // no storage (private mode, etc.) — skip quietly.
			}
			const approvedIds = store.requests.filter((r) => r.status === 'APPROVED').map((r) => r.id)
			const persist = () => {
				try {
					window.localStorage.setItem(CELEBRATED_KEY, JSON.stringify(approvedIds))
				} catch { /* ignore */ }
			}
			// First run on this browser: adopt the existing approvals silently so the
			// backlog never sets off a burst — only future approvals celebrate.
			if (raw === null) {
				persist()
				return
			}
			let seen
			try {
				seen = new Set(JSON.parse(raw))
			} catch {
				persist()
				return
			}
			const today = toIso(new Date())
			const fresh = store.requests.filter((r) => {
				if (r.status !== 'APPROVED' || seen.has(r.id) || r.endDate < today) {
					return false
				}
				// Only a genuine trip to look forward to — never sick leave, and never
				// a withheld confidential absence (its type is null on this view).
				const type = store.leaveType(r.typeId)
				return type.countsAgainstBalance === true && type.key && type.key !== 'sick'
			})
			persist()
			if (!fresh.length) {
				return
			}
			window.dispatchEvent(new CustomEvent('absence:celebrate'))
			const type = store.leaveType(fresh[0].typeId)
			showSuccess(t('absence', 'Your {type} is approved — enjoy! ✈️', { type: type.label.toLowerCase() }))
		},
	},
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
