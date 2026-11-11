<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="rli" :class="{ 'rli--active': active }" :style="{ '--type-color': type.color }">
		<NcListItem
			:name="title"
			:active="active"
			:forceDisplayActions="true"
			@click="select">
			<template #icon>
				<span class="rli__icon" :style="{ background: colorSoft }" aria-hidden="true">{{ type.icon }}</span>
			</template>
			<template #subname>
				{{ subtitle }}
			</template>
			<template v-if="showStatus" #indicator>
				<StatusChip :status="request.status" />
			</template>
		</NcListItem>
	</div>
</template>

<script setup lang="ts">
import type { LeaveRequest } from '../types/index.ts'

import { n } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import StatusChip from './StatusChip.vue'
import { useLeaveTypesStore } from '../stores/leaveTypes.ts'
import { formatRange } from '../utils/dates.ts'

const props = withDefaults(defineProps<{
	request: LeaveRequest
	active?: boolean
	showEmployee?: boolean
	// The employee's remaining days for this leave type and year, when the caller
	// has them to hand. Null for leave that counts against no allowance, and while
	// the figures are still loading.
	remaining?: number | null
}>(), {
	active: false,
	showEmployee: false,
	remaining: null,
})

const emit = defineEmits<{
	select: [id: number]
}>()

const leaveTypesStore = useLeaveTypesStore()

const type = computed(() => leaveTypesStore.leaveType(props.request.typeId))

const showStatus = computed(() => leaveTypesStore.statusVisible(props.request))

const colorSoft = computed(() => `color-mix(in srgb, ${type.value.color} 18%, transparent)`)

/** Older payloads carry no name; the uid is a poor label but better than blank. */
const employeeName = computed(() => props.request.employeeName || props.request.employeeUid)

const title = computed(() => {
	if (props.showEmployee) {
		return `${employeeName.value} · ${type.value.label}`
	}
	return type.value.label
})

const subtitle = computed(() => {
	const range = formatRange(props.request.startDate, props.request.endDate)
	const days = n('absence', '%n day', '%n days', props.request.workingDays)
	if (props.remaining === null) {
		return `${range} · ${days}`
	}
	// Days taken alone does not answer the question the list is scanned for.
	const left = n('absence', '%n day left', '%n days left', Math.round(props.remaining * 10) / 10)
	return `${range} · ${days} · ${left}`
})

/**
 * Selecting a row opens the detail sidebar — it must not navigate.
 *
 * NcListItem always renders an anchor and defaults its href to "#", and it
 * only calls preventDefault() itself when given a `to` (router-link) prop.
 * Left alone, the browser follows that "#" — which under this app's hash
 * history is the route "/", redirecting to My leave. So an HR user opening
 * someone's absence was thrown back to their own overview.
 *
 * @param event the native click NcListItem forwards
 */
function select(event?: MouseEvent) {
	event?.preventDefault?.()
	emit('select', props.request.id)
}
</script>

<style scoped lang="scss">
.rli {
	position: relative;
	border-radius: var(--border-radius-large, 12px);
	transition: transform 150ms ease, background-color 150ms ease;

	// Leave-type accent as a straight vertical bar on the left (not following the
	// row's rounded corners).
	&::before {
		content: '';
		position: absolute;
		inset-inline-start: 0;
		top: 8px;
		bottom: 8px;
		width: 3px;
		border-radius: 3px;
		background: var(--type-color);
		z-index: 1;
	}

	&:hover {
		background: var(--color-background-hover);
		transform: translateY(-1px);
	}

	&--active {
		background: var(--color-background-hover);
	}

	// Round the list item so the stripe + hover background clip nicely.
	:deep(.list-item),
	:deep(.list-item__wrapper) {
		border-radius: var(--border-radius-large, 12px);
	}

	&__icon {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 40px;
		height: 40px;
		border-radius: 50%;
		font-size: 1.2rem;
	}
}

@media (prefers-reduced-motion: reduce) {
	.rli {
		transition: none;
		&:hover { transform: none; }
	}
}
</style>
