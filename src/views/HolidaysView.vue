<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="page">
		<header class="page__header">
			<h2 class="page__title">
				{{ t('absence', 'Public holidays') }}
			</h2>
			<div class="page__tools">
				<NcSelect
					v-model="region"
					:options="regionOptions"
					:clearable="false"
					label="label"
					:aria-label-combobox="t('absence', 'Region')" />
				<NcSelect
					v-model="year"
					:options="years"
					:clearable="false"
					:aria-label-combobox="t('absence', 'Year')" />
				<NcButton :disabled="!shown.length" @click="download">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('absence', 'Download') }}
				</NcButton>
				<NcButton @click="openHistory">
					<template #icon>
						<History :size="20" />
					</template>
					{{ t('absence', 'History') }}
				</NcButton>
				<NcButton v-if="session.isHr" @click="openImport">
					<template #icon>
						<Upload :size="20" />
					</template>
					{{ t('absence', 'Import') }}
				</NcButton>
				<NcButton v-if="canEdit" variant="primary" @click="edit(null)">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('absence', 'Add') }}
				</NcButton>
			</div>
		</header>

		<p class="holidays__intro">
			{{ t('absence', 'People get the days of their region code, of its country, and the days entered for * (everywhere). DE-BY gets DE-BY, DE and * days.') }}
			<template v-if="!canEdit">
				{{ t('absence', 'Only HR edits public holidays.') }}
			</template>
		</p>
		<p v-if="session.isHr" class="holidays__who">
			{{ session.holidaysEveryoneEdits ? t('absence', 'Who may edit: everyone.') : t('absence', 'Who may edit: HR only.') }}
			<RouterLink :to="{ name: 'hr-settings' }">
				{{ t('absence', 'Change in Settings') }}
			</RouterLink>
		</p>

		<SkeletonList v-if="loading" :rows="6" />

		<div v-else class="table-wrap">
			<table class="tbl">
				<thead>
					<tr>
						<th>{{ t('absence', 'Date') }}</th>
						<th>{{ t('absence', 'Region') }}</th>
						<th>{{ t('absence', 'Name') }}</th>
						<th v-if="canEdit" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="holiday in shown" :key="holiday.id + '-' + holiday.date">
						<td>{{ formatDate(holiday.date) }}</td>
						<td>
							<span class="holidays__region"><span aria-hidden="true">{{ regionFlag(holiday.region) }}</span> {{ regionName(holiday.region) }}</span>
						</td>
						<td>
							{{ holiday.label }}
							<span v-if="holiday.recurring" class="holidays__recurring">{{ t('absence', 'every year') }}</span>
							<div v-if="byline(holiday)" class="holidays__byline">
								{{ byline(holiday) }}
							</div>
						</td>
						<td v-if="canEdit" class="holidays__actions">
							<NcButton variant="tertiary" :ariaLabel="t('absence', 'Edit {name}', { name: holiday.label }, undefined, { escape: false })" @click="edit(holiday)">
								<template #icon>
									<Pencil :size="18" />
								</template>
							</NcButton>
							<NcButton variant="tertiary" :ariaLabel="t('absence', 'Delete {name}', { name: holiday.label }, undefined, { escape: false })" @click="deleting = holiday">
								<template #icon>
									<Delete :size="18" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<NcEmptyContent
				v-if="!shown.length"
				:name="t('absence', 'No public holidays for {year}', { year })"
				:description="canEdit ? t('absence', 'Add them one by one, or import a whole year as JSON.') : ''">
				<template #icon>
					<PartyPopper :size="20" />
				</template>
			</NcEmptyContent>
		</div>

		<NcModal v-if="form" :name="form.id ? t('absence', 'Edit holiday') : t('absence', 'Add holiday')" @close="form = null">
			<form class="holidays__form" @submit.prevent="save">
				<h3>{{ form.id ? t('absence', 'Edit holiday') : t('absence', 'Add holiday') }}</h3>
				<NcDateTimePickerNative
					v-model="form.date"
					type="date"
					:label="t('absence', 'Date')"
					required />
				<NcTextField
					:modelValue="form.region"
					:label="t('absence', 'Region code')"
					:helperText="t('absence', 'A country like DE, a region like DE-BY (ISO 3166-2), or * for everywhere')"
					required
					@update:modelValue="form.region = String($event)" />
				<NcTextField
					:modelValue="form.label"
					:label="t('absence', 'Name')"
					required
					@update:modelValue="form.label = String($event)" />
				<NcCheckboxRadioSwitch v-model="form.recurring">
					{{ t('absence', 'Every year on this day') }}
				</NcCheckboxRadioSwitch>
				<div class="holidays__buttons">
					<NcButton variant="tertiary" @click="form = null">
						{{ t('absence', 'Cancel') }}
					</NcButton>
					<NcButton type="submit" variant="primary" :disabled="saving">
						{{ t('absence', 'Save') }}
					</NcButton>
				</div>
			</form>
		</NcModal>

		<NcDialog
			v-if="deleting"
			:name="t('absence', 'Delete {name}?', { name: deleting.label }, undefined, { escape: false })"
			:open="true"
			size="small"
			@update:open="(open) => !open && !removing && (deleting = null)">
			<p>
				{{ deleting.recurring
					? t('absence', 'This is a recurring entry: it is deleted from every year, not only from {year}.', { year })
					: t('absence', 'This deletes the entry of {date}. It is not a recurring entry.', { date: formatDate(deleting.date) }) }}
			</p>
			<template #actions>
				<NcButton :disabled="removing" @click="deleting = null">
					{{ t('absence', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" :disabled="removing" @click="remove">
					{{ deleting.recurring ? t('absence', 'Delete from every year') : t('absence', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcModal
			v-if="importing"
			:name="t('absence', 'Import holidays')"
			size="large"
			@close="importing = false">
			<div class="holidays__form">
				<h3>{{ t('absence', 'Import holidays') }}</h3>
				<p>{{ t('absence', 'Paste a JSON list. Days already in the list are skipped, and nothing is imported while an entry is invalid.') }}</p>
				<NcTextArea
					v-model="json"
					class="holidays__json"
					:label="t('absence', 'Holidays as JSON')"
					:placeholder="example"
					resize="vertical"
					rows="12"
					@update:modelValue="plan = null" />
				<p v-if="importError" class="holidays__error">
					{{ importError }}
				</p>
				<template v-if="plan">
					<p>
						<strong>{{ n('absence', '{count} new holiday', '{count} new holidays', plan.new.length, { count: plan.new.length }) }}</strong>
						{{ n('absence', '· {count} already there', '· {count} already there', plan.existing, { count: plan.existing }) }}
					</p>
					<ul class="holidays__preview">
						<li v-for="entry in plan.new.slice(0, 10)" :key="entry.region + entry.date">
							{{ formatDate(entry.date) }} · {{ regionName(entry.region) }} · {{ entry.label }}
						</li>
						<li v-if="plan.new.length > 10">
							{{ n('absence', 'and {count} more', 'and {count} more', plan.new.length - 10, { count: plan.new.length - 10 }) }}
						</li>
					</ul>
				</template>
				<div class="holidays__buttons">
					<NcButton variant="tertiary" @click="importing = false">
						{{ t('absence', 'Cancel') }}
					</NcButton>
					<NcButton
						v-if="!plan"
						variant="primary"
						:disabled="saving || !json.trim()"
						@click="check">
						{{ t('absence', 'Check') }}
					</NcButton>
					<NcButton
						v-else
						variant="primary"
						:disabled="saving || !plan.new.length"
						@click="runImport">
						{{ n('absence', 'Import {count} holiday', 'Import {count} holidays', plan.new.length, { count: plan.new.length }) }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<NcModal
			v-if="history"
			:name="t('absence', 'History')"
			size="normal"
			@close="history = null">
			<div class="holidays__form">
				<h3>{{ t('absence', 'Changes to the public holidays') }}</h3>
				<p v-if="!history.length">
					{{ t('absence', 'No changes yet.') }}
				</p>
				<ul class="holidays__history">
					<li v-for="(change, i) in history" :key="i">
						<span class="holidays__when">{{ formatWhen(change.at) }}</span>
						{{ describeChange(change) }}
					</li>
				</ul>
			</div>
		</NcModal>
	</div>
</template>

<script setup lang="ts">
import type { Holiday, HolidayChange, HolidayData, HolidayDescription, HolidayImportResult } from '../types/index.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, onMounted, ref, shallowRef, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Download from 'vue-material-design-icons/Download.vue'
import History from 'vue-material-design-icons/History.vue'
import PartyPopper from 'vue-material-design-icons/PartyPopper.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/TrashCanOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import SkeletonList from '../components/SkeletonList.vue'
import api from '../services/api.ts'
import { useSessionStore } from '../stores/session.ts'
import { formatDate, toIso } from '../utils/dates.ts'
import { serverMessage } from '../utils/errors.ts'
import { latestOnly } from '../utils/latest.ts'
import { locale } from '../utils/locale.ts'
import { EVERYWHERE, regionFlag } from '../utils/regions.ts'

const sessionStore = useSessionStore()

/** An entry of the region filter: `id` is the region code, '' for all of them */
interface RegionOption {
	id: string
	label: string
}

/** The add and edit form: `id` is null for a new holiday */
interface HolidayForm {
	id: number | null
	date: Date | null
	region: string
	label: string
	recurring: boolean
}

const currentYear = new Date().getFullYear()
const all: RegionOption = { id: '', label: t('absence', 'All regions') }

const loading = ref(true)
const holidays = shallowRef<Holiday[]>([])
const year = ref(currentYear)
const years = [currentYear - 1, currentYear, currentYear + 1, currentYear + 2]
const region = shallowRef<RegionOption>(all)
const form = ref<HolidayForm | null>(null)
const saving = ref(false)
// The holiday whose deletion waits for a confirmation
const deleting = ref<Holiday | null>(null)
const removing = ref(false)
const importing = ref(false)
const json = ref('')
const plan = ref<HolidayImportResult | null>(null)
const importError = ref('')
const history = shallowRef<HolidayChange[] | null>(null)
const example = JSON.stringify([
	{ date: `${currentYear + 1}-01-01`, region: 'DE', label: t('absence', 'New Year\'s Day') },
	{ date: `${currentYear + 1}-12-24`, region: EVERYWHERE, label: t('absence', 'Company day off'), recurring: true },
], null, 2)

// Changing the year twice quickly: only the year picked last may land.
const startLoad = latestOnly()

const session = computed(() => sessionStore.session)

const canEdit = computed(() => sessionStore.session.canEditHolidays)

const regionOptions = computed<RegionOption[]>(() => {
	const codes = [...new Set(holidays.value.map((h) => h.region))].sort()
	return [all, ...codes.map((code) => ({ id: code, label: `${regionFlag(code)} ${regionName(code)}`.trim() }))]
})

const shown = computed(() => (region.value.id === '' ? holidays.value : holidays.value.filter((h) => h.region === region.value.id)))

watch(year, () => {
	load()
})

onMounted(() => {
	load()
})

/**
 * The name to show for a region code.
 *
 * @param code a region code, or * for everywhere
 */
function regionName(code: string): string {
	return code === EVERYWHERE ? t('absence', 'Everywhere') : code
}

/**
 * Loads the holidays of the selected year.
 */
async function load(): Promise<void> {
	const isLatest = startLoad()
	loading.value = true
	try {
		const list = await api.listHolidays(year.value)
		if (isLatest()) {
			holidays.value = list
		}
	} catch {
		if (isLatest()) {
			showError(t('absence', 'Could not load the public holidays'))
		}
	} finally {
		if (isLatest()) {
			loading.value = false
		}
	}
}

/**
 * Opens the form on a holiday, or empty to add one.
 *
 * @param holiday the holiday to edit, null to add one
 */
function edit(holiday: Holiday | null): void {
	form.value = holiday
		? { id: holiday.id, date: new Date(holiday.date + 'T00:00:00'), region: holiday.region, label: holiday.label, recurring: holiday.recurring }
		: { id: null, date: new Date(`${year.value}-01-01T00:00:00`), region: region.value.id, label: '', recurring: false }
}

/**
 * Saves the holiday of the form.
 */
async function save(): Promise<void> {
	saving.value = true
	try {
		if (!form.value?.date) {
			throw new Error('No date to save')
		}
		const data: HolidayData = { date: toIso(form.value.date), region: form.value.region, label: form.value.label, recurring: form.value.recurring }
		await (form.value.id ? api.updateHoliday(form.value.id, data) : api.createHoliday(data))
		showSuccess(t('absence', 'Holiday saved'))
		form.value = null
		await load()
	} catch (e) {
		showError(serverMessage(e) || t('absence', 'Could not save the holiday'))
	} finally {
		saving.value = false
	}
}

/**
 * Deletes the holiday that waits for a confirmation.
 */
async function remove(): Promise<void> {
	const holiday = deleting.value
	removing.value = true
	try {
		if (!holiday) {
			throw new Error('No holiday to delete')
		}
		await api.deleteHoliday(holiday.id)
		showSuccess(holiday.recurring
			? t('absence', '{name} deleted from every year', { name: holiday.label }, undefined, { escape: false })
			: t('absence', '{name} deleted', { name: holiday.label }, undefined, { escape: false }))
		deleting.value = null
		await load()
	} catch (e) {
		showError(serverMessage(e) || t('absence', 'Could not delete the holiday'))
	} finally {
		removing.value = false
	}
}

/**
 * Opens the import dialog.
 */
function openImport(): void {
	importing.value = true
	plan.value = null
	importError.value = ''
}

/**
 * Asks the server what importing the pasted list would add.
 */
async function check(): Promise<void> {
	saving.value = true
	importError.value = ''
	try {
		plan.value = await api.importHolidays(json.value, true)
	} catch (e) {
		importError.value = serverMessage(e) || t('absence', 'Could not read the list')
	} finally {
		saving.value = false
	}
}

/**
 * Imports the pasted list.
 */
async function runImport(): Promise<void> {
	saving.value = true
	try {
		const result = await api.importHolidays(json.value, false)
		showSuccess(n('absence', '{count} holiday imported', '{count} holidays imported', result.new.length, { count: result.new.length }))
		importing.value = false
		json.value = ''
		await load()
	} catch (e) {
		importError.value = serverMessage(e) || t('absence', 'Could not import the list')
	} finally {
		saving.value = false
	}
}

/**
 * Who added and who changed a holiday, '' when nobody is known.
 *
 * @param holiday the holiday of the row
 */
function byline(holiday: Holiday): string {
	if (holiday.createdByName && holiday.updatedByName) {
		return t('absence', 'Added by {added} · changed by {changed}', { added: holiday.createdByName, changed: holiday.updatedByName }, undefined, { escape: false })
	}
	if (holiday.createdByName) {
		return t('absence', 'Added by {name}', { name: holiday.createdByName }, undefined, { escape: false })
	}
	return holiday.updatedByName ? t('absence', 'Changed by {name}', { name: holiday.updatedByName }, undefined, { escape: false }) : ''
}

/**
 * Loads the history and opens its dialog.
 */
async function openHistory(): Promise<void> {
	try {
		history.value = await api.holidayHistory()
	} catch {
		showError(t('absence', 'Could not load the history'))
	}
}

/**
 * Formats the time of a change.
 *
 * @param atom ISO 8601 timestamp
 */
function formatWhen(atom: string): string {
	return new Date(atom).toLocaleString(locale(), { dateStyle: 'medium', timeStyle: 'short' })
}

/**
 * One sentence for an entry of the history.
 *
 * @param change the entry
 */
function describeChange(change: HolidayChange): string {
	const who = change.actorName || t('absence', 'An administrator')
	const day = (d: HolidayDescription) => `${d.label} (${formatDate(d.date)}, ${regionName(d.region)})`
	// Kept as a string for the default branch: a newer server may know more actions
	const action: string = change.action
	switch (change.action) {
		case 'added':
			return t('absence', '{who} added {day}', { who, day: day(change.detail) }, undefined, { escape: false })
		case 'deleted':
			return t('absence', '{who} deleted {day}', { who, day: day(change.detail) }, undefined, { escape: false })
		case 'changed':
			return t('absence', '{who} changed {before} to {after}', { who, before: day(change.detail.before), after: day(change.detail.after) }, undefined, { escape: false })
		case 'imported':
			return n('absence', '{who} imported {count} holiday', '{who} imported {count} holidays', change.detail.count, { who, count: change.detail.count }, { escape: false })
		case 'everyone_edits':
			return t('absence', '{who} let everyone edit public holidays', { who }, undefined, { escape: false })
		case 'hr_edits':
			return t('absence', '{who} made public holidays editable by HR only', { who }, undefined, { escape: false })
		default:
			return action
	}
}

/**
 * Downloads the shown holidays as a JSON file.
 */
function download(): void {
	// The import format, so a year can be edited and imported again
	const list = shown.value.map(({ date, region, label, recurring }) => ({ date, region, label, recurring }))
	const link = document.createElement('a')
	link.href = URL.createObjectURL(new Blob([JSON.stringify(list, null, '\t')], { type: 'application/json' }))
	link.download = `holidays-${year.value}${region.value.id ? '-' + region.value.id : ''}.json`
	link.click()
	URL.revokeObjectURL(link.href)
}
</script>

<style scoped lang="scss">
.holidays {
	&__intro {
		margin-block-end: calc(2 * var(--default-grid-baseline));
		color: var(--color-text-maxcontrast);
	}

	&__region {
		font-variant-numeric: tabular-nums;
		font-weight: bold;
	}

	&__who {
		margin-block-end: calc(2 * var(--default-grid-baseline));

		a {
			text-decoration: underline;
		}
	}

	&__byline,
	&__when {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}

	&__when {
		margin-inline-end: var(--default-grid-baseline);
	}

	&__history {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__recurring {
		margin-inline-start: var(--default-grid-baseline);
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		display: flex;
		justify-content: flex-end;
	}

	&__form {
		display: flex;
		flex-direction: column;
		gap: calc(2 * var(--default-grid-baseline));
		padding: calc(4 * var(--default-grid-baseline));
	}

	&__json :deep(textarea) {
		font-family: var(--font-face-monospace, monospace);
	}

	&__error {
		white-space: pre-line;
		color: var(--color-error-text);
	}

	&__preview {
		margin: 0;
		padding-inline-start: calc(4 * var(--default-grid-baseline));
		list-style: disc;
	}

	&__buttons {
		display: flex;
		justify-content: flex-end;
		gap: var(--default-grid-baseline);
	}
}
</style>
