<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - HR enters someone's start and end date and their working pattern from a date
  - on (§6.4). A change of pattern is a new row with its own date, so the past
  - keeps the pattern it had.
-->
<template>
	<form class="contract-editor" @submit.prevent="save">
		<div class="contract-editor__dates">
			<NcDateTimePickerNative
				:modelValue="toDate(start)"
				type="date"
				:label="t('absence', 'Start date')"
				@update:modelValue="(v) => start = fromDate(v)" />
			<NcDateTimePickerNative
				:modelValue="toDate(end)"
				type="date"
				:label="t('absence', 'End date (optional)')"
				@update:modelValue="(v) => end = fromDate(v)" />
		</div>

		<table class="tbl contract-editor__periods">
			<thead>
				<tr>
					<th>{{ t('absence', 'From') }}</th>
					<th>{{ t('absence', 'Working days') }}</th>
					<th>{{ t('absence', 'Contract') }}</th>
					<th><span class="hidden-visually">{{ t('absence', 'Actions') }}</span></th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(period, index) in periods" :key="period.key">
					<td>
						<NcDateTimePickerNative
							:modelValue="toDate(period.from)"
							type="date"
							:label="t('absence', 'From')"
							hideLabel
							@update:modelValue="(v) => period.from = fromDate(v)" />
					</td>
					<td>
						<div class="contract-editor__days" role="group" :aria-label="t('absence', 'Working days')">
							<NcButton
								v-for="day in weekdays"
								:key="day.iso"
								:variant="period.weekdays.includes(day.iso) ? 'primary' : 'tertiary'"
								:pressed="period.weekdays.includes(day.iso)"
								:aria-label="day.label"
								size="small"
								@update:pressed="(on) => toggle(period, day.iso, on)">
								{{ day.short }}
							</NcButton>
						</div>
					</td>
					<td>
						<NcTextField
							v-model="period.percent"
							class="contract-editor__percent"
							type="number"
							min="1"
							max="100"
							:label="t('absence', 'Percent')" />
					</td>
					<td>
						<NcButton variant="tertiary" @click="periods.splice(index, 1)">
							{{ t('absence', 'Delete') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-if="!periods.length" class="contract-editor__hint">
			{{ t('absence', 'No working pattern yet: Monday to Friday is assumed.') }}
		</p>

		<div class="contract-editor__actions">
			<NcButton @click="addPeriod">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('absence', 'Add a change') }}
			</NcButton>
			<NcButton type="submit" variant="primary" :disabled="saving">
				{{ t('absence', 'Save') }}
			</NcButton>
		</div>
	</form>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Plus from 'vue-material-design-icons/Plus.vue'
import api from '../api.js'
import { toIso } from '../utils/dates.js'

let nextKey = 0

export default {
	name: 'ContractEditor',
	components: { NcButton, NcDateTimePickerNative, NcTextField, Plus },
	props: {
		uid: { type: String, required: true },
		contract: { type: Object, required: true },
	},

	emits: ['saved'],

	data() {
		return {
			start: this.contract.start,
			end: this.contract.end,
			// Newest first, like the history
			periods: this.contract.periods
				.map((period) => ({ ...period, weekdays: [...period.weekdays], percent: period.percent ?? '', key: nextKey++ }))
				.reverse(),

			weekdays: [
				{ iso: 1, short: t('absence', 'Mon'), label: t('absence', 'Monday') },
				{ iso: 2, short: t('absence', 'Tue'), label: t('absence', 'Tuesday') },
				{ iso: 3, short: t('absence', 'Wed'), label: t('absence', 'Wednesday') },
				{ iso: 4, short: t('absence', 'Thu'), label: t('absence', 'Thursday') },
				{ iso: 5, short: t('absence', 'Fri'), label: t('absence', 'Friday') },
				{ iso: 6, short: t('absence', 'Sat'), label: t('absence', 'Saturday') },
				{ iso: 7, short: t('absence', 'Sun'), label: t('absence', 'Sunday') },
			],

			saving: false,
		}
	},

	methods: {
		t,

		toDate(iso) {
			return iso ? new Date(iso + 'T00:00:00') : null
		},

		fromDate(date) {
			return date ? toIso(date) : null
		},

		toggle(period, iso, on) {
			period.weekdays = on
				? [...period.weekdays, iso].sort()
				: period.weekdays.filter((day) => day !== iso)
		},

		addPeriod() {
			const latest = this.periods[0]
			this.periods.unshift({
				from: toIso(new Date()),
				weekdays: latest ? [...latest.weekdays] : [1, 2, 3, 4, 5],
				percent: latest ? latest.percent : 100,
				key: nextKey++,
			})
		},

		async save() {
			this.saving = true
			try {
				const contract = await api.updateContract(this.uid, {
					start: this.start,
					end: this.end,
					periods: this.periods.map((period) => ({
						from: period.from,
						weekdays: period.weekdays,
						percent: period.percent === '' ? null : Number(period.percent),
					})),
				})
				this.$emit('saved', contract)
				showSuccess(t('absence', 'Contract saved'))
			} catch (e) {
				showError(e.response?.data?.message || t('absence', 'Could not save the contract'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.contract-editor {
	&__dates {
		display: flex;
		flex-wrap: wrap;
		gap: 12px 16px;
		margin-bottom: 12px;
	}

	&__periods {
		display: block;
		overflow-x: auto;
	}

	&__days {
		display: flex;
		gap: 4px;

		:deep(.button-vue) {
			min-width: auto;
			padding-inline: 8px;
		}
	}

	&__percent {
		max-width: 110px;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		margin: 8px 0;
	}

	&__actions {
		display: flex;
		justify-content: space-between;
		margin-top: 12px;
	}
}
</style>
