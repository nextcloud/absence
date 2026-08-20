<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - A small, warm summary of the year's time off so far: days taken, trips
  - booked, the longest break and the busiest month. Reads from the store's own
  - approved requests; renders nothing until there is at least one trip to show.
-->
<template>
	<section v-if="stats" class="yir">
		<header class="yir__head">
			<span class="yir__spark" aria-hidden="true">✨</span>
			<h3 class="yir__title">
				{{ t('absence', 'Your year so far ({year})', { year }) }}
			</h3>
		</header>
		<div class="yir__grid">
			<div class="yir__tile">
				<span class="yir__value">{{ format(stats.days) }}</span>
				<span class="yir__label">{{ t('absence', 'days of leave taken') }}</span>
			</div>
			<div class="yir__tile">
				<span class="yir__value">{{ stats.trips }}</span>
				<span class="yir__label">{{ n('absence', 'trip booked', 'trips booked', stats.trips) }}</span>
			</div>
			<div class="yir__tile">
				<span class="yir__value">{{ format(stats.longest) }}</span>
				<span class="yir__label">{{ t('absence', 'longest break, in days') }}</span>
			</div>
			<div v-if="stats.favouriteMonth" class="yir__tile">
				<span class="yir__value yir__value--word">{{ stats.favouriteMonth }}</span>
				<span class="yir__label">{{ t('absence', 'your busiest month') }}</span>
			</div>
		</div>
	</section>
</template>

<script>
import { n, t } from '@nextcloud/l10n'
import { store } from '../store.js'
import { addWorkingDaysByMonth } from '../utils/dates.js'

export default {
	name: 'YearInReview',

	props: {
		year: { type: Number, required: true },
	},

	setup() {
		return { store }
	},

	computed: {
		/**
		 * Summary of this year's approved, balance-counting leave (holidays and the
		 * like — not sick leave, which is not a "trip"). Null when there is nothing
		 * to celebrate yet, so the card stays hidden.
		 */
		stats() {
			const mine = store.requests.filter((r) => {
				if (r.status !== 'APPROVED') {
					return false
				}
				if (Number(r.startDate.slice(0, 4)) !== this.year) {
					return false
				}
				// Genuine, balance-counting leave only — not sick leave, and not a
				// withheld confidential absence (whose type is null on this view).
				const type = store.leaveType(r.typeId)
				return type.countsAgainstBalance === true && type.key && type.key !== 'sick'
			})
			if (!mine.length) {
				return null
			}
			const months = new Array(12).fill(0)
			let days = 0
			let longest = 0
			for (const r of mine) {
				days += r.workingDays
				longest = Math.max(longest, r.workingDays)
				addWorkingDaysByMonth(months, r.startDate, r.endDate, r.workingDays, this.year)
			}
			let favouriteMonth = null
			const peak = Math.max(...months)
			if (peak > 0) {
				favouriteMonth = new Date(this.year, months.indexOf(peak), 1)
					.toLocaleDateString(undefined, { month: 'long' })
			}
			return {
				days: Math.round(days * 10) / 10,
				trips: mine.length,
				longest: Math.round(longest * 10) / 10,
				favouriteMonth,
			}
		},
	},

	methods: {
		t,
		n,
		format(v) {
			return Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 })
		},
	},
}
</script>

<style scoped lang="scss">
.yir {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	border-radius: var(--border-radius-large, 12px);
	// A quiet, festive wash that reads in both themes.
	background: linear-gradient(135deg,
		color-mix(in srgb, var(--color-primary-element) 16%, var(--color-main-background)),
		color-mix(in srgb, var(--color-primary-element) 5%, var(--color-main-background)));
	border: 1px solid color-mix(in srgb, var(--color-primary-element) 24%, transparent);

	&__head {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-bottom: 14px;
	}

	&__spark {
		font-size: 1.3rem;
		line-height: 1;
	}

	&__title {
		margin: 0;
		font-size: 1.1rem;
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
		gap: calc(var(--default-grid-baseline, 4px) * 3);
	}

	&__tile {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__value {
		font-size: 1.9rem;
		font-weight: 700;
		line-height: 1.1;
		color: var(--color-main-text);

		&--word {
			font-size: 1.4rem;
			// Long month names should not blow the tile width out.
			overflow-wrap: anywhere;
		}
	}

	&__label {
		font-size: 0.82rem;
		color: var(--color-text-maxcontrast);
	}
}
</style>
