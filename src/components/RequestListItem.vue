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
			@click="$emit('select', request.id)">
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

<script>
import { n, t } from '@nextcloud/l10n'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import StatusChip from './StatusChip.vue'
import { store } from '../store.js'
import { formatRange } from '../utils/dates.js'

export default {
	name: 'RequestListItem',
	components: { NcListItem, StatusChip },
	props: {
		request: { type: Object, required: true },
		active: { type: Boolean, default: false },
		showEmployee: { type: Boolean, default: false },
	},

	emits: ['select'],
	computed: {
		type() {
			return store.leaveType(this.request.typeId)
		},

		showStatus() {
			return store.statusVisible(this.request)
		},

		colorSoft() {
			return `color-mix(in srgb, ${this.type.color} 18%, transparent)`
		},

		title() {
			if (this.showEmployee) {
				return `${this.request.employeeUid} · ${this.type.label}`
			}
			return this.type.label
		},

		subtitle() {
			const range = formatRange(this.request.startDate, this.request.endDate)
			const days = n('absence', '%n day', '%n days', this.request.workingDays)
			return `${range} · ${days}`
		},
	},

	methods: { t, n },
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
