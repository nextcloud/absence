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
			<NcButton variant="tertiary" :aria-label="t('absence', 'Previous month')" @click="shift(-1)">
				<template #icon>
					<ChevronLeft :size="20" />
				</template>
			</NcButton>
			<strong class="gantt__month">{{ monthLabel }}</strong>
			<NcButton variant="tertiary" :aria-label="t('absence', 'Next month')" @click="shift(1)">
				<template #icon>
					<ChevronRight :size="20" />
				</template>
			</NcButton>
			<NcButton variant="tertiary" @click="goToday">
				{{ t('absence', 'Today') }}
			</NcButton>
		</div>

		<SkeletonList v-if="loading" :rows="4" class="gantt__loading" />

		<div v-else class="gantt__scroll">
			<div class="gantt__grid" :style="{ '--day-w': dayWidth + 'px', '--days': days.length }">
				<!-- header -->
				<div class="gantt__row gantt__row--head">
					<div class="gantt__name gantt__name--head">
						{{ t('absence', 'Person') }}
					</div>
					<div class="gantt__track">
						<span
							v-for="d in days"
							:key="'h' + d.day"
							class="gantt__daynum"
							:class="{ 'gantt__daynum--weekend': d.weekend, 'gantt__daynum--today': d.index === todayIndex }"
							:style="{ left: d.index * dayWidth + 'px' }">{{ d.day }}</span>
					</div>
				</div>

				<!-- rows -->
				<div v-for="row in rows" :key="row.uid" class="gantt__row">
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
							:class="{ 'gantt__col--weekend': d.weekend }"
							:style="{ left: d.index * dayWidth + 'px' }" />
						<span v-if="todayIndex >= 0" class="gantt__today" :style="{ left: (todayIndex * dayWidth) + 'px' }" />
						<span
							v-for="(seg, i) in row.segments"
							:key="i"
							class="gantt__pill"
							:class="{ 'gantt__pill--pending': seg.pending }"
							:style="{ left: seg.left + 'px', width: seg.width + 'px', '--pill': seg.color }"
							:title="seg.title">
							<span class="gantt__pill-icon" aria-hidden="true">{{ seg.icon }}</span>
						</span>
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
			<span v-for="lt in legendTypes" :key="lt.id" class="legend__item">
				<span class="legend__swatch" :style="{ background: lt.color }" />{{ lt.icon }} {{ lt.label }}
			</span>
			<span class="legend__item legend__item--muted">
				<span class="legend__swatch legend__swatch--pending" />{{ t('absence', 'Pending / not yet approved') }}
			</span>
		</div>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import CalendarBlank from 'vue-material-design-icons/CalendarBlank.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import SkeletonList from './SkeletonList.vue'
import api from '../api.js'
import { store } from '../store.js'
import { formatRange, toIso } from '../utils/dates.js'

const DAY_MS = 86400000

export default {
	name: 'TeamTimeline',
	components: { NcAvatar, NcButton, NcEmptyContent, ChevronLeft, ChevronRight, CalendarBlank, SkeletonList },
	props: {
		scope: { type: String, default: 'team' },
	},

	data() {
		const now = new Date()
		return {
			year: now.getFullYear(),
			month: now.getMonth(),
			events: [],
			dayWidth: 30,
			loading: true,
		}
	},

	computed: {
		firstDay() {
			return new Date(this.year, this.month, 1)
		},

		lastDay() {
			return new Date(this.year, this.month + 1, 0)
		},

		monthLabel() {
			return this.firstDay.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
		},

		days() {
			const arr = []
			for (let i = 1; i <= this.lastDay.getDate(); i++) {
				const dt = new Date(this.year, this.month, i)
				const dow = dt.getDay()
				arr.push({ day: i, index: i - 1, weekend: dow === 0 || dow === 6, iso: toIso(dt) })
			}
			return arr
		},

		todayIndex() {
			const now = new Date()
			if (now.getFullYear() === this.year && now.getMonth() === this.month) {
				return now.getDate() - 1
			}
			return -1
		},

		rows() {
			const byUid = {}
			const monthStart = this.firstDay
			const lastIndex = this.lastDay.getDate() - 1
			for (const ev of this.events) {
				if (!byUid[ev.employeeUid]) {
					byUid[ev.employeeUid] = { uid: ev.employeeUid, name: ev.displayName, segments: [] }
				}
				const startIdx = Math.max(0, Math.round((new Date(ev.start + 'T00:00:00') - monthStart) / DAY_MS))
				const endIdx = Math.min(lastIndex, Math.round((new Date(ev.end + 'T00:00:00') - monthStart) / DAY_MS))
				if (endIdx < 0 || startIdx > lastIndex) {
					continue
				}
				const type = store.leaveType(ev.typeId)
				byUid[ev.employeeUid].segments.push({
					left: startIdx * this.dayWidth + 2,
					width: (endIdx - startIdx + 1) * this.dayWidth - 4,
					color: type.color,
					icon: type.icon,
					pending: ev.status !== 'APPROVED',
					title: `${type.label} · ${formatRange(ev.start, ev.end)}${ev.status !== 'APPROVED' ? ' (' + ev.status + ')' : ''}`,
				})
			}
			return Object.values(byUid).sort((a, b) => a.name.localeCompare(b.name))
		},

		legendTypes() {
			const ids = new Set(this.events.map((e) => e.typeId))
			return store.leaveTypes.filter((lt) => ids.has(lt.id))
		},
	},

	watch: {
		scope() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		async load() {
			this.loading = true
			try {
				this.events = (await api.getCalendar(toIso(this.firstDay), toIso(this.lastDay), this.scope)).events
			} catch {
				this.events = []
			} finally {
				this.loading = false
			}
		},

		shift(delta) {
			let m = this.month + delta
			let y = this.year
			if (m < 0) {
				m = 11
				y--
			}
			if (m > 11) {
				m = 0
				y++
			}
			this.month = m
			this.year = y
			this.load()
		},

		goToday() {
			const now = new Date()
			this.year = now.getFullYear()
			this.month = now.getMonth()
			this.load()
		},
	},
}
</script>

<style scoped lang="scss">
$name-w: 180px;

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
		left: 0;
		z-index: 2;
		flex: 0 0 #{$name-w};
		width: $name-w;
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 8px 12px;
		background: var(--color-main-background);
		border-right: 1px solid var(--color-border);

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
		width: calc(var(--days) * var(--day-w));
		flex: 0 0 auto;
	}

	&__daynum {
		position: absolute;
		top: 50%;
		transform: translateY(-50%);
		width: var(--day-w);
		text-align: center;
		font-size: 0.72rem;
		color: var(--color-text-maxcontrast);

		&--weekend { color: var(--color-primary-element); }
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
		width: var(--day-w);

		&--weekend { background: var(--color-background-dark); }
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
		height: 26px;
		display: flex;
		align-items: center;
		padding: 0 8px;
		border-radius: var(--border-radius-pill, 14px);
		background: var(--pill);
		color: #fff;
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

		&--pending {
			background: repeating-linear-gradient(45deg, var(--color-text-maxcontrast), var(--color-text-maxcontrast) 3px, transparent 3px, transparent 6px);
		}
	}
}

@media (prefers-reduced-motion: reduce) {
	.gantt__pill { animation: none; }
}
</style>
