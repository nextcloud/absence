<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Gantt-style month timeline: one row per person with a sticky avatar rail and
  - continuous rounded leave pills across the day track (spec §15.2).
-->
<template>
	<div class="gantt">
		<div class="gantt__toolbar">
			<NcButton variant="tertiary" :ariaLabel="t('absence', 'Previous month')" @click="shift(-1)">
				<template #icon>
					<ChevronLeft :size="20" />
				</template>
			</NcButton>
			<strong class="gantt__month">{{ monthLabel }}</strong>
			<NcButton variant="tertiary" :ariaLabel="t('absence', 'Next month')" @click="shift(1)">
				<template #icon>
					<ChevronRight :size="20" />
				</template>
			</NcButton>
			<NcButton variant="tertiary" @click="goToday">
				{{ t('absence', 'Today') }}
			</NcButton>
		</div>

		<SkeletonList v-if="loading" :rows="4" class="gantt__loading" />

		<NcEmptyContent
			v-else-if="loadError"
			:name="t('absence', 'Could not load the absences')"
			:description="t('absence', 'Something went wrong while fetching the absences of this month.')">
			<template #icon>
				<AlertCircleOutline :size="20" />
			</template>
			<template #action>
				<NcButton @click="load">
					{{ t('absence', 'Try again') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<div v-else class="gantt__scroll">
			<!-- Read out as a list of people, each with their absences as text: the
			     day columns only mean something to the eye. -->
			<div
				class="gantt__grid"
				role="list"
				:aria-label="t('absence', 'Absences in {month}', { month: monthLabel })"
				:style="{ '--days': days.length }">
				<!-- header -->
				<div class="gantt__row gantt__row--head" aria-hidden="true">
					<div class="gantt__name gantt__name--head">
						{{ t('absence', 'Person') }}
					</div>
					<div class="gantt__track">
						<span
							v-for="d in days"
							:key="'h' + d.day"
							class="gantt__daynum"
							:class="{ 'gantt__daynum--today': d.index === todayIndex }"
							:style="{ '--day': d.index }">{{ d.day }}</span>
					</div>
				</div>

				<!-- rows -->
				<div
					v-for="row in rows"
					:key="row.uid"
					class="gantt__row"
					role="listitem">
					<div class="gantt__name">
						<NcAvatar
							:user="row.uid"
							:displayName="row.name"
							:size="26"
							hideStatus />
						<span class="gantt__name-text">{{ row.name }}</span>
					</div>
					<div class="gantt__track">
						<span
							v-for="d in days"
							:key="'c' + row.uid + d.day"
							class="gantt__col"
							:class="{ 'gantt__col--weekend': d.weekend, 'gantt__col--holiday': row.holidays.has(d.iso) }"
							:title="row.holidays.get(d.iso)"
							:style="{ '--day': d.index }">
							<span v-if="row.holidays.has(d.iso)" class="hidden-visually">{{ holidayLabel(row, d.iso) }}</span>
						</span>
						<span v-if="todayIndex >= 0" class="gantt__today" :style="{ '--day': todayIndex }" />
						<component
							:is="selectable ? 'button' : 'span'"
							v-for="(seg, i) in row.segments"
							:key="i"
							class="gantt__pill"
							:class="{
								'gantt__pill--pending': seg.pending,
								'gantt__pill--clickable': selectable,
								'gantt__pill--active': selectable && requestsStore.selectedId === seg.requestId,
							}"
							:type="selectable ? 'button' : null"
							:style="{ '--day': seg.first, '--length': seg.length, '--pill': seg.color, '--pill-text': seg.textColor }"
							:title="seg.title"
							@click="selectable && $emit('select', seg.requestId)">
							<span class="gantt__pill-icon" aria-hidden="true">{{ seg.icon }}</span>
							<span class="hidden-visually">{{ seg.label }}</span>
						</component>
					</div>
				</div>
			</div>

			<NcEmptyContent
				v-if="!rows.length"
				:name="t('absence', 'No absences this month')"
				:description="t('absence', 'A calm, well-staffed month. ☀️')">
				<template #icon>
					<CalendarBlank :size="20" />
				</template>
			</NcEmptyContent>
		</div>

		<div class="legend">
			<span v-for="item in legend" :key="item.key" class="legend__item">
				<span class="legend__swatch" :style="{ background: item.color }" />{{ item.icon }} {{ item.label }}
			</span>
			<span class="legend__item legend__item--muted">
				<span class="legend__swatch legend__swatch--pending" />{{ t('absence', 'Pending / not yet approved') }}
			</span>
			<span v-if="hasHolidays" class="legend__item legend__item--muted">
				<span class="legend__swatch legend__swatch--holiday" />{{ t('absence', 'Public holiday for this person') }}
			</span>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { CoverageEvent, CoverageScope, JsonMap, PersonHoliday } from '../types/index.ts'
import type { TimelineRow } from '../utils/timeline.ts'

import { t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import CalendarBlank from 'vue-material-design-icons/CalendarBlank.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import SkeletonList from './SkeletonList.vue'
import api from '../services/api.ts'
import { useLeaveTypesStore } from '../stores/leaveTypes.ts'
import { useRequestsStore } from '../stores/requests.ts'
import { formatDate, toIso } from '../utils/dates.ts'
import { latestOnly } from '../utils/latest.ts'
import { locale } from '../utils/locale.ts'
import { timelineLegend, timelineRows } from '../utils/timeline.ts'

const props = withDefaults(defineProps<{
	scope?: CoverageScope
	// Turn the pills into buttons that open the request. Only set it where the
	// viewer may actually read every request in the timeline (HR's "Who's off") —
	// elsewhere the calendar can include leave whose detail the API would refuse.
	selectable?: boolean
}>(), {
	scope: 'team',
	selectable: false,
})
defineEmits<{
	select: [requestId: number]
}>()
const requestsStore = useRequestsStore()
const leaveTypesStore = useLeaveTypesStore()

/** One day column of the month */
interface TimelineDay {
	day: number
	index: number
	weekend: boolean
	iso: string
}

const today = new Date()
const year = ref(today.getFullYear())
const month = ref(today.getMonth())
const events = shallowRef<CoverageEvent[]>([])
const holidays = shallowRef<JsonMap<PersonHoliday[]>>([])
const loading = ref(true)
const loadError = ref(false)

// Fast month navigation: only the month asked for last may land.
const startLoad = latestOnly()

const firstDay = computed(() => new Date(year.value, month.value, 1))

const lastDay = computed(() => new Date(year.value, month.value + 1, 0))

const monthLabel = computed(() => firstDay.value.toLocaleDateString(locale(), { month: 'long', year: 'numeric' }))

const days = computed(() => {
	const arr: TimelineDay[] = []
	for (let i = 1; i <= lastDay.value.getDate(); i++) {
		const dt = new Date(year.value, month.value, i)
		const dow = dt.getDay()
		arr.push({ day: i, index: i - 1, weekend: dow === 0 || dow === 6, iso: toIso(dt) })
	}
	return arr
})

const todayIndex = computed(() => {
	const now = new Date()
	if (now.getFullYear() === year.value && now.getMonth() === month.value) {
		return now.getDate() - 1
	}
	return -1
})

const rows = computed(() => timelineRows(events.value, holidays.value, year.value, month.value, leaveTypesStore.leaveType))

const hasHolidays = computed(() => rows.value.some((row) => row.holidays.size > 0))

const legend = computed(() => timelineLegend(events.value, leaveTypesStore.leaveTypes, leaveTypesStore.leaveType))

watch(() => props.scope, () => {
	load()
})

onMounted(() => {
	load()
	// A cancellation made through the sidebar must drop off the timeline.
	window.addEventListener('absence:refresh', load)
})

onBeforeUnmount(() => {
	window.removeEventListener('absence:refresh', load)
})

/**
 * Fetch the absences of the month on show.
 */
async function load() {
	const isLatest = startLoad()
	loading.value = true
	loadError.value = false
	try {
		const fetched = await api.getCalendar(toIso(firstDay.value), toIso(lastDay.value), props.scope)
		if (isLatest()) {
			events.value = fetched.events
			holidays.value = fetched.holidays
		}
	} catch {
		if (isLatest()) {
			events.value = []
			holidays.value = []
			loadError.value = true
		}
	} finally {
		if (isLatest()) {
			loading.value = false
		}
	}
}

/**
 * What a screen reader gets for a shaded day.
 *
 * @param row the person
 * @param iso the day, 'YYYY-MM-DD'
 */
function holidayLabel(row: TimelineRow, iso: string): string {
	return t('absence', 'Public holiday on {date}: {name}', { date: formatDate(iso), name: row.holidays.get(iso) ?? '' }, undefined, { escape: false, sanitize: false })
}

/**
 * Move to another month.
 *
 * @param delta the number of months to move by, -1 or 1
 */
function shift(delta: number) {
	let m = month.value + delta
	let y = year.value
	if (m < 0) {
		m = 11
		y--
	}
	if (m > 11) {
		m = 0
		y++
	}
	month.value = m
	year.value = y
	load()
}

/**
 * Come back to the current month.
 */
function goToday() {
	const now = new Date()
	year.value = now.getFullYear()
	month.value = now.getMonth()
	load()
}
</script>

<style scoped lang="scss">
$name-w: 180px;
// Below this width per day the day area scrolls sideways instead of shrinking
$day-min-w: 24px;

.gantt {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);

	&__toolbar {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	&__month {
		font-size: 1.1rem;
		min-width: 150px;
		text-align: center;
	}

	&__scroll {
		overflow-x: auto;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);

		// Keep a strip clear below the last row. The horizontal scrollbar lives
		// inside this box, and an overlay scrollbar (the macOS default) is painted
		// *over* the content rather than taking space of its own — so with the
		// pills sitting only 8px clear of the track's bottom edge it cut the last
		// row's pill and its selection ring in half. Only the last row could ever
		// show this, which is exactly how it was reported.
		padding-block-end: 12px;

		// This is a scroll container, so its automatic minimum size in a flex
		// column is 0 — a height-constrained ancestor could shrink it and clip
		// rows outright. Nothing constrains it today; this keeps it that way.
		flex: 0 0 auto;
	}

	&__grid {
		min-width: max-content;
	}

	&__row {
		display: flex;
		align-items: stretch;
		border-bottom: 1px solid var(--color-border-dark, var(--color-border));

		&:last-child { border-bottom: none; }

		&--head {
			position: sticky;
			top: 0;
			z-index: 3;
			background: var(--color-main-background);
		}
	}

	&__name {
		position: sticky;
		inset-inline-start: 0;
		z-index: 2;
		flex: 0 0 #{$name-w};
		width: $name-w;
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 8px 12px;
		background: var(--color-main-background);
		border-inline-end: 1px solid var(--color-border);

		&--head {
			font-size: 0.8rem;
			color: var(--color-text-maxcontrast);
			text-transform: uppercase;
			letter-spacing: 0.04em;
		}
	}

	&__name-text {
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__track {
		position: relative;
		height: 42px;
		flex: 1 1 auto;
		min-width: calc(var(--days) * #{$day-min-w});
	}

	// Day columns, the today line and the pills sit at a share of the track
	// width, so the whole month fits whatever the width.
	&__daynum,
	&__col,
	&__today {
		inset-inline-start: calc(var(--day) * 100% / var(--days));
	}

	&__daynum,
	&__col {
		width: calc(100% / var(--days));
	}

	&__daynum {
		position: absolute;
		top: 50%;
		transform: translateY(-50%);
		text-align: center;
		font-size: 0.72rem;
		color: var(--color-text-maxcontrast);

		&--today {
			color: var(--color-primary-element-text, #fff);
			background: var(--color-primary-element);
			border-radius: 8px;
			padding: 1px 0;
		}
	}

	&__col {
		position: absolute;
		top: 0;
		bottom: 0;

		&--weekend { background: var(--color-background-dark); }
		// After the weekend so a holiday on a Saturday still shows as one
		&--holiday { background: var(--color-primary-element-light); }
	}

	&__today {
		position: absolute;
		top: 0;
		bottom: 0;
		width: 2px;
		background: var(--color-primary-element);
		opacity: 0.7;
		z-index: 1;
	}

	&__pill {
		position: absolute;
		top: 8px;
		inset-inline-start: calc(var(--day) * 100% / var(--days) + 2px);
		width: calc(var(--length) * 100% / var(--days) - 4px);
		height: 26px;
		display: flex;
		align-items: center;
		padding: 0 8px;
		border-radius: var(--border-radius-pill, 14px);
		background: var(--pill);
		color: var(--pill-text, #fff);
		font-size: 0.85rem;
		box-shadow: 0 1px 3px rgba(0, 0, 0, 0.18);
		overflow: hidden;
		z-index: 1;
		animation: pill-in 300ms ease both;

		&--pending {
			background: repeating-linear-gradient(
				45deg,
				var(--pill),
				var(--pill) 6px,
				color-mix(in srgb, var(--pill) 55%, transparent) 6px,
				color-mix(in srgb, var(--pill) 55%, transparent) 12px
			);
			opacity: 0.9;
		}

		// As a <button> the pill inherits the browser's control styling, which
		// would otherwise override the shared look above.
		&--clickable {
			border: none;
			font: inherit;
			text-align: start;
			cursor: pointer;

			&:hover {
				filter: brightness(1.08);
				box-shadow: 0 2px 6px rgba(0, 0, 0, 0.28);
			}

			&:focus-visible {
				outline: 2px solid var(--color-main-text);
				outline-offset: 1px;
			}
		}

		&--active {
			outline: 2px solid var(--color-main-text);
			outline-offset: 1px;
		}
	}
}

@keyframes pill-in {
	from { opacity: 0; transform: scaleX(0.9); transform-origin: left; }
	to { opacity: 1; transform: scaleX(1); }
}

.legend {
	display: flex;
	flex-wrap: wrap;
	gap: 14px;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);

	&__item {
		display: inline-flex;
		align-items: center;
		gap: 6px;
	}

	&__swatch {
		width: 14px;
		height: 14px;
		border-radius: 4px;

		&--holiday { background: var(--color-primary-element-light); }

		&--pending {
			background: repeating-linear-gradient(45deg, var(--color-text-maxcontrast), var(--color-text-maxcontrast) 3px, transparent 3px, transparent 6px);
		}
	}
}

@media (prefers-reduced-motion: reduce) {
	.gantt__pill { animation: none; }
}
</style>
