<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - The holiday region code a person sets for themselves (§7.2), never guessed.
  - A changed code first shows which days off it adds and removes.
-->
<template>
	<div class="region-code">
		<div class="region-code__row">
			<NcTextField
				:modelValue="code"
				:label="t('absence', 'My region code')"
				:error="code !== '' && !codeValid"
				:helperText="code !== '' && !codeValid ? t('absence', 'Use a country like DE or a region like DE-BY') : ''"
				@update:modelValue="code = String($event)" />
			<NcButton :disabled="saving || !codeValid || code === savedCode" @click="save">
				{{ t('absence', 'Change') }}
			</NcButton>
		</div>
		<p v-if="knownCodes.length" class="region-code__hint">
			{{ t('absence', 'Codes with holidays: {codes}', { codes: knownCodes.map((known) => `${regionFlag(known)} ${known}`).join(', ') }, undefined, { escape: false }) }}
		</p>

		<NcDialog
			:open="change !== null"
			size="normal"
			:name="t('absence', 'Change your holiday region?')"
			:buttons="changeButtons"
			@update:open="change = null">
			<template v-if="change">
				<NcNoteCard v-if="change.noHolidays" type="warning">
					{{ t('absence', 'No public holidays are defined for {code}: only the days for everywhere apply. Check the code, or get its holidays added on the Public holidays page.', { code: change.code }) }}
				</NcNoteCard>
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

import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import api from '../services/api.ts'
import { useSessionStore } from '../stores/session.ts'
import { parseWeekdays } from '../utils/dates.ts'
import { serverMessage } from '../utils/errors.ts'
import { compareHolidays, formatRegionCode, groupByYear, hasHolidays, parseRegionCode } from '../utils/holidays.ts'
import { locale } from '../utils/locale.ts'
import { regionCountryName, regionFlag } from '../utils/regions.ts'

const props = defineProps<{
	/** The saved code, e.g. `DE-BY`, empty when none */
	savedCode: string
}>()

const emit = defineEmits<{
	saved: [code: string]
}>()

const sessionStore = useSessionStore()

/** What moving from the saved code to the typed one changes */
interface RegionChange {
	before: string
	code: string
	noHolidays: boolean
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

const codeValid = computed(() => parseRegionCode(code.value) !== null)

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
		knownCodes.value = (await api.listHolidayRegions()).filter((known) => known !== '*')
	} catch {
		// Only the hint is missing
	}
})

/**
 * @param regionCode a region code, '' for none
 * @return the code with its flag and country, as far as they are known
 */
function describeCode(regionCode: string): string {
	if (!regionCode) {
		return t('absence', 'No region code')
	}
	const country = regionCountryName(regionCode)
	return country ? `${regionFlag(regionCode)} ${regionCode} · ${country}` : regionCode
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
 * Loads what the typed code changes and shows it, for the person to confirm.
 */
async function save() {
	const parsed = parseRegionCode(code.value)
	if (!parsed) {
		return
	}
	const typed = formatRegionCode(parsed.country, parsed.region)
	saving.value = true
	try {
		const year = new Date().getFullYear()
		const [before, after] = await Promise.all([
			api.listApplyingHolidays(props.savedCode, `${year}-01-01`, `${year + 1}-12-31`),
			api.listApplyingHolidays(typed, `${year}-01-01`, `${year + 1}-12-31`),
		])
		change.value = {
			before: props.savedCode,
			code: typed,
			noHolidays: typed !== '' && !hasHolidays(typed, knownCodes.value),
			...compareHolidays(before, after),
		}
	} catch {
		showError(t('absence', 'Could not load the holidays of {code}', { code: typed }))
	} finally {
		saving.value = false
	}
}

/**
 * Saves the typed code, once confirmed.
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
		.catch((e: unknown) => showError(serverMessage(e) || t('absence', 'Could not save the region code')))
		.finally(() => {
			saving.value = false
		})
}
</script>

<style scoped lang="scss">
.region-code {
	&__row {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		max-width: 420px;

		> :last-child {
			flex-shrink: 0;
		}
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;
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
		font-size: 1rem;
		font-weight: 600;
	}

	&__count {
		padding: 0 8px;
		border-radius: var(--border-radius-pill);
		font-size: 0.85rem;
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
		font-size: 0.8rem;
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
		font-size: 0.8rem;
	}
}
</style>
