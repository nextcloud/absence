<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - The vacation days of a year on one's own profile (§15.2): a ring split in
  - taken, planned and waiting days around the days left, what the days are
  - made of, the carried-over days about to expire and the next leave.
-->
<template>
	<div class="vacation" :style="{ '--type-color': row.typeColor }">
		<NcNoteCard v-if="row.carryOverExpiring > 0" type="warning">
			{{ n('absence', '%n carried-over day expires on {date}. Plan it before then, or it is gone.', '%n carried-over days expire on {date}. Plan them before then, or they are gone.', row.carryOverExpiring, { date: formatDate(row.carryOverExpiryDate) }) }}
		</NcNoteCard>

		<div class="vacation__body">
			<div class="vacation__ring" role="img" :aria-label="ringLabel">
				<svg viewBox="0 0 120 120" class="vacation__svg" aria-hidden="true">
					<defs>
						<pattern
							:id="patternId"
							width="6"
							height="6"
							patternUnits="userSpaceOnUse"
							patternTransform="rotate(45)">
							<rect width="3" height="6" class="vacation__stripe" />
							<rect
								x="3"
								width="3"
								height="6"
								class="vacation__stripe vacation__stripe--light" />
						</pattern>
					</defs>
					<circle
						class="vacation__track"
						cx="60"
						cy="60"
						:r="RADIUS" />
					<circle
						v-for="segment in segments"
						:key="segment.key"
						class="vacation__segment"
						:class="'vacation__segment--' + segment.key"
						cx="60"
						cy="60"
						:r="RADIUS"
						:stroke="segment.key === 'pending' ? `url(#${patternId})` : undefined"
						:stroke-dasharray="`${segment.length} ${CIRCUMFERENCE}`"
						:stroke-dashoffset="-segment.offset"
						transform="rotate(-90 60 60)" />
				</svg>
				<div class="vacation__centre">
					<span class="vacation__left">{{ formatNumber(left) }}</span>
					<span class="vacation__unit">{{ n('absence', 'day left', 'days left', left) }}</span>
				</div>
				<div class="vacation__type">
					<span aria-hidden="true">{{ row.typeIcon }}</span>
					{{ row.typeLabel }}
				</div>
			</div>

			<div class="vacation__facts">
				<p class="vacation__sum">
					<strong>{{ n('absence', '%n day', '%n days', total) }}</strong>
					= {{ composition }}
				</p>
				<ul class="vacation__legend">
					<li v-for="entry in legend" :key="entry.key">
						<span class="vacation__dot" :class="'vacation__dot--' + entry.key" aria-hidden="true" />
						{{ entry.label }}
						<strong class="vacation__count">{{ formatNumber(entry.value) }}</strong>
					</li>
				</ul>
				<p v-if="split.next" class="vacation__next">
					{{ t('absence', 'Next:') }}
					<strong>{{ formatShortRange(split.next.startDate, split.next.endDate, new Date().getFullYear()) }}</strong>
					·
					{{ split.next.pending
						? n('absence', '%n day, waiting for approval', '%n days, waiting for approval', split.next.workingDays)
						: n('absence', '%n day', '%n days', split.next.workingDays) }}
				</p>
				<slot />
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { BalanceRow, LeaveRequest } from '../types/index.ts'

import { n, t } from '@nextcloud/l10n'
import { computed, useId } from 'vue'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { formatDate, formatShortRange, toIso } from '../utils/dates.ts'
import { formatNumber } from '../utils/locale.ts'
import { vacationSplit } from '../utils/vacation.ts'

const props = defineProps<{
	row: BalanceRow
	/** The person's requests, to tell taken from planned days */
	requests: LeaveRequest[]
}>()

const RADIUS = 50
const CIRCUMFERENCE = 2 * Math.PI * RADIUS
// A hair of space between two segments
const GAP = 1.5

const patternId = `vacation-pending-${useId()}`

const split = computed(() => vacationSplit(props.row, props.requests, toIso(new Date())))
const total = computed(() => props.row.entitlement ?? 0)
const left = computed(() => props.row.available ?? 0)

/** The taken, planned and waiting days as arcs, one after the other */
const segments = computed(() => {
	const parts = [
		{ key: 'taken', value: split.value.taken },
		{ key: 'planned', value: split.value.planned },
		{ key: 'pending', value: split.value.pending },
	].filter((part) => part.value > 0)
	const whole = Math.max(total.value, parts.reduce((sum, part) => sum + part.value, 0))
	if (whole <= 0) {
		return []
	}
	let offset = 0
	return parts.map((part) => {
		const length = CIRCUMFERENCE * part.value / whole
		const segment = { key: part.key, offset, length: Math.max(length - (parts.length > 1 ? GAP : 0), 0.5) }
		offset += length
		return segment
	})
})

const legend = computed(() => [
	{ key: 'taken', label: t('absence', 'Taken'), value: split.value.taken },
	{ key: 'planned', label: t('absence', 'Planned'), value: split.value.planned },
	{ key: 'pending', label: t('absence', 'Waiting for approval'), value: split.value.pending },
	{ key: 'left', label: t('absence', 'Left to plan'), value: left.value },
].filter((entry) => entry.key === 'left' || entry.value > 0))

/** "25 for 2026 + 3 carried over", with HR's adjustment when there is one */
const composition = computed(() => {
	const { baseDays, carryOverDays, manualAdjustment, year } = props.row
	const parts = [t('absence', '{days} for {year}', { days: formatNumber(baseDays), year: String(year) })]
	if (carryOverDays > 0) {
		parts.push('+ ' + t('absence', '{days} carried over', { days: formatNumber(carryOverDays) }))
	}
	if (manualAdjustment !== 0) {
		parts.push((manualAdjustment > 0 ? '+ ' : '− ') + t('absence', '{days} adjusted by HR', { days: formatNumber(Math.abs(manualAdjustment)) }))
	}
	return parts.join(' ')
})

const ringLabel = computed(() => n('absence', '{type}: %n day left of {total}', '{type}: %n days left of {total}', left.value, { type: props.row.typeLabel, total: formatNumber(total.value) }, { escape: false }))
</script>

<style scoped lang="scss">
.vacation {
	--vacation-taken: var(--type-color, var(--color-success));
	--vacation-planned: color-mix(in srgb, var(--vacation-taken) 45%, var(--color-main-background));
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));

	&__body {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: calc(6 * var(--default-grid-baseline));
	}

	&__ring {
		position: relative;
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 8px;
		flex: none;
	}

	&__svg {
		width: 140px;
		height: 140px;
	}

	&__track,
	&__segment {
		fill: none;
		stroke-width: 12;
	}

	&__track {
		stroke: var(--color-background-darker, var(--color-border));
	}

	&__segment {
		transition: stroke-dasharray 900ms cubic-bezier(0.4, 0, 0.2, 1);

		&--taken {
			stroke: var(--vacation-taken);
		}

		&--planned {
			stroke: var(--vacation-planned);
		}
	}

	&__stripe {
		fill: var(--color-warning-text, var(--color-warning));

		&--light {
			fill: color-mix(in srgb, var(--color-warning) 40%, var(--color-main-background));
		}
	}

	&__centre {
		position: absolute;
		top: 0;
		inset-inline-start: 0;
		width: 140px;
		height: 140px;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
	}

	&__left {
		font-size: var(--absence-font-size-figure);
		font-weight: 700;
		line-height: 1;
	}

	&__unit {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small);
	}

	&__type {
		display: flex;
		gap: 6px;
		font-weight: 600;
	}

	&__facts {
		flex: 1 1 220px;
		min-width: 0;
	}

	&__sum {
		margin: 0 0 8px;
	}

	&__legend {
		display: flex;
		flex-direction: column;
		gap: 4px;
		margin: 0 0 8px;
		padding: 0;
		list-style: none;

		li {
			display: flex;
			align-items: center;
			gap: 8px;
		}
	}

	&__count {
		margin-inline-start: auto;
		font-variant-numeric: tabular-nums;
	}

	&__dot {
		flex: none;
		width: 12px;
		height: 12px;
		border-radius: 3px;

		&--taken {
			background: var(--vacation-taken);
		}

		&--planned {
			background: var(--vacation-planned);
		}

		&--pending {
			background: repeating-linear-gradient(45deg, var(--color-warning-text, var(--color-warning)) 0 3px, color-mix(in srgb, var(--color-warning) 40%, var(--color-main-background)) 3px 6px);
		}

		&--left {
			background: var(--color-background-darker, var(--color-border));
		}
	}

	&__next {
		margin: 0 0 8px;
		color: var(--color-text-maxcontrast);
	}
}

@media (prefers-reduced-motion: reduce) {
	.vacation__segment {
		transition: none;
	}
}
</style>
