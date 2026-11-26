<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - The holiday region code a person sets for themselves (§7.2), never guessed.
  - A changed code first shows which days off it adds and removes.
-->
<template>
	<div class="region-code">
		<NcSelect
			class="region-code__select"
			:modelValue="selected"
			:options="items"
			:filter="filterRegionPickerItems"
			:selectable="(item: RegionPickerItem) => !item.heading"
			:inputLabel="t('absence', 'My country or region')"
			:placeholder="t('absence', 'Choose your country or region')"
			:helperText="t('absence', 'Sets your public holidays. Not listed? Ask HR to add your country.')"
			:loading="saving"
			:disabled="saving"
			label="label"
			@update:modelValue="pick">
			<template #option="item">
				<span v-if="item.heading" class="region-code__heading">{{ item.label }}</span>
				<span v-else class="region-code__option">
					<span class="region-code__flag" aria-hidden="true">{{ item.flag }}</span>
					<span class="region-code__name">
						{{ item.label }}
						<span v-if="item.country" class="region-code__country">{{ item.country }}</span>
					</span>
					<span class="region-code__code">{{ item.code }}</span>
				</span>
			</template>
			<template #selected-option="item">
				<span class="region-code__option">
					<span class="region-code__flag" aria-hidden="true">{{ item.flag }}</span>
					<span class="region-code__name">{{ item.label }}</span>
					<span class="region-code__code">{{ item.code }}</span>
				</span>
			</template>
		</NcSelect>

		<NcDialog
			:open="change !== null"
			size="normal"
			:name="t('absence', 'Change your holiday region?')"
			:buttons="changeButtons"
			@update:open="onDialogClosed">
			<template v-if="change">
				<p class="change__switch">
					<span class="change__code">{{ describeCode(change.before) }}</span>
					<span aria-hidden="true">→</span>
					<span class="hidden-visually">{{ t('absence', 'to') }}</span>
					<span class="change__code">{{ describeCode(change.code) }}</span>
				</p>
				<div class="change__columns">
					<section
						v-for="column in changeColumns"
						:key="column.key"
						class="change__column"
						:class="'change__column--' + column.key">
						<h3 class="change__title">
							{{ column.title }}
							<span class="change__count">{{ column.count }}</span>
						</h3>
						<p v-if="!column.years.length" class="region-code__hint">
							{{ t('absence', 'None') }}
						</p>
						<template v-for="group in column.years" :key="group.year">
							<h4 class="change__year">
								{{ group.year }}
							</h4>
							<ul class="change__list">
								<li v-for="day in group.days" :key="day.date" :class="{ 'change__day--off': !isWorkingDay(day.date) }">
									<span class="change__date">{{ shortDate(day.date) }}</span>
									<span>
										{{ day.label }}
										<span v-if="!isWorkingDay(day.date)" class="change__tag">{{ t('absence', 'day off anyway') }}</span>
									</span>
								</li>
							</ul>
						</template>
					</section>
				</div>
				<p class="region-code__hint">
					{{ n('absence', '{count} day stays the same.', '{count} days stay the same.', change.kept, { count: change.kept }) }}
				</p>
			</template>
		</NcDialog>
	</div>
</template>

<script setup lang="ts">
import type { ButtonVariant } from '@nextcloud/vue/components/NcButton'
import type { HolidayRecord } from '../types/index.ts'
import type { RegionPickerItem } from '../utils/regions.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, onMounted, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import api from '../services/api.ts'
import { useSessionStore } from '../stores/session.ts'
import { parseWeekdays } from '../utils/dates.ts'
import { serverMessage } from '../utils/errors.ts'
import { compareHolidays, formatRegionCode, groupByYear, parseRegionCode } from '../utils/holidays.ts'
import { locale } from '../utils/locale.ts'
import { filterRegionPickerItems, regionFlag, regionName, regionPickerItems } from '../utils/regions.ts'

const props = defineProps<{
	/** The saved code, e.g. `DE-BY`, empty when none */
	savedCode: string
}>()

const emit = defineEmits<{
	saved: [code: string]
}>()

const sessionStore = useSessionStore()

/** What moving from the saved code to the picked one changes */
interface RegionChange {
	before: string
	code: string
	lost: HolidayRecord[]
	gained: HolidayRecord[]
	kept: number
}

/** One side of the comparison: the days lost or the days gained */
interface ChangeColumn {
	key: 'lost' | 'gained'
	title: string
	count: string
	years: { year: string, days: HolidayRecord[] }[]
}

/** A button of the confirmation dialog */
interface DialogButton {
	label: string
	variant?: ButtonVariant
	callback: () => void
}

const code = ref(props.savedCode)
const knownCodes = ref<string[]>([])
// What a changed code would change, shown before saving it
const change = ref<RegionChange | null>(null)
const saving = ref(false)

const items = computed(() => regionPickerItems(knownCodes.value, props.savedCode))
const selected = computed(() => items.value.find((item) => !item.heading && item.code === code.value) ?? null)

/** The days lost and gained, each split by year */
const changeColumns = computed<ChangeColumn[]>(() => {
	const current = change.value
	if (!current) {
		return []
	}
	return [
		{ key: 'lost', title: t('absence', 'No longer days off'), count: `−${current.lost.length}`, years: groupByYear(current.lost) },
		{ key: 'gained', title: t('absence', 'New days off'), count: `+${current.gained.length}`, years: groupByYear(current.gained) },
	]
})

const workingWeekdays = computed(() => parseWeekdays(sessionStore.session.workWeekdays))

const changeButtons = computed<DialogButton[]>(() => [
	{ label: t('absence', 'Cancel'), callback: () => {} },
	{ label: t('absence', 'Change region'), variant: 'primary', callback: () => storeCode() },
])

watch(() => props.savedCode, (saved) => {
	code.value = saved
})

onMounted(async () => {
	try {
		knownCodes.value = await api.listHolidayRegions()
	} catch {
		showError(t('absence', 'Could not load the countries and regions with public holidays'))
	}
})

/**
 * @param regionCode a region code, '' for none
 * @return the code with its flag and name, as far as they are known
 */
function describeCode(regionCode: string): string {
	if (!regionCode) {
		return t('absence', 'No region code')
	}
	const name = regionName(regionCode)
	return name === regionCode ? `${regionFlag(regionCode)} ${regionCode}`.trim() : `${regionFlag(regionCode)} ${name} · ${regionCode}`
}

/**
 * @param iso 'YYYY-MM-DD'
 */
function shortDate(iso: string): string {
	return new Date(iso + 'T00:00:00').toLocaleDateString(locale(), { weekday: 'short', day: 'numeric', month: 'short' })
}

/**
 * @param iso 'YYYY-MM-DD'
 */
function isWorkingDay(iso: string): boolean {
	const day = new Date(iso + 'T00:00:00').getDay()
	return workingWeekdays.value.has(day === 0 ? 7 : day)
}

/**
 * Shows what the picked code changes, for the person to confirm.
 *
 * @param item the picked entry, null when cleared
 */
async function pick(item: RegionPickerItem | null) {
	const picked = item?.code ?? ''
	if (picked === props.savedCode) {
		return
	}
	code.value = picked
	saving.value = true
	try {
		const year = new Date().getFullYear()
		const [before, after] = await Promise.all([
			api.listApplyingHolidays(props.savedCode, `${year}-01-01`, `${year + 1}-12-31`),
			api.listApplyingHolidays(picked, `${year}-01-01`, `${year + 1}-12-31`),
		])
		change.value = { before: props.savedCode, code: picked, ...compareHolidays(before, after) }
	} catch {
		code.value = props.savedCode
		showError(t('absence', 'Could not load the holidays of {code}', { code: picked }))
	} finally {
		saving.value = false
	}
}

/**
 * Goes back to the saved code when the change is cancelled, not while it is saved.
 */
function onDialogClosed() {
	change.value = null
	if (!saving.value) {
		code.value = props.savedCode
	}
}

/**
 * Saves the picked code, once confirmed.
 */
function storeCode() {
	const parsed = parseRegionCode(code.value)
	if (!parsed) {
		return
	}
	saving.value = true
	api.updatePersonalConfig({ holiday_country: parsed.country, holiday_region: parsed.region })
		.then((updated) => {
			emit('saved', formatRegionCode(updated.holidayCountry, updated.holidayRegion))
			showSuccess(t('absence', 'Region code saved'))
		})
		.catch((e: unknown) => {
			code.value = props.savedCode
			showError(serverMessage(e) || t('absence', 'Could not save the region code'))
		})
		.finally(() => {
			saving.value = false
		})
}
</script>

<style scoped lang="scss">
.region-code {
	&__select {
		max-width: 420px;
	}

	&__heading {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small);
		font-weight: 600;
	}

	&__option {
		display: flex;
		align-items: center;
		gap: 8px;
		min-width: 0;
	}

	&__flag {
		font-size: 1.2em;
	}

	&__name {
		display: flex;
		flex-direction: column;
		flex: 1;
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__country {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small);
	}

	&__code {
		padding: 0 8px;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small);
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small);
		margin: 8px 0;
	}
}

.change {
	&__switch {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 10px 14px;
		margin: 0 0 16px;
		border-radius: var(--border-radius-large);
		background: var(--color-background-hover);
	}

	&__code {
		font-weight: 600;
	}

	&__columns {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
		gap: 12px;
	}

	&__column {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		padding: 10px 12px;
	}

	&__title {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0 0 4px;
		font-size: var(--default-font-size);
		font-weight: 600;
	}

	&__count {
		padding: 0 8px;
		border-radius: var(--border-radius-pill);
		font-size: var(--font-size-small);
	}

	&__column--lost &__count {
		color: var(--color-error-text);
		background: color-mix(in srgb, var(--color-error) 15%, transparent);
	}

	&__column--gained &__count {
		color: var(--color-success-text);
		background: color-mix(in srgb, var(--color-success) 15%, transparent);
	}

	&__year {
		margin: 8px 0 2px;
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small);
		font-weight: 600;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: 4px;
		margin: 0;
		padding: 0;
		list-style: none;

		li {
			display: grid;
			grid-template-columns: 110px 1fr;
			gap: 8px;
		}
	}

	&__date {
		color: var(--color-text-maxcontrast);
		font-variant-numeric: tabular-nums;
	}

	&__day--off {
		opacity: 0.6;
	}

	&__tag {
		margin-inline-start: 4px;
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small);
	}
}
</style>

<style lang="scss">
// The menu is appended to the body, out of reach of the scoped styles:
// a group heading is not an entry, so it gets no highlight
.vs__dropdown-option--disabled:has(.region-code__heading) {
	background: transparent !important;
	cursor: default;
}
</style>
