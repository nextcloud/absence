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
							<NcButton variant="tertiary" :aria-label="t('absence', 'Edit {name}', { name: holiday.label }, undefined, { escape: false })" @click="edit(holiday)">
								<template #icon>
									<Pencil :size="18" />
								</template>
							</NcButton>
							<NcButton variant="tertiary" :aria-label="t('absence', 'Delete {name}', { name: holiday.label }, undefined, { escape: false })" @click="remove(holiday)">
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
					v-model="form.region"
					:label="t('absence', 'Region code')"
					:helperText="t('absence', 'A country like DE, a region like DE-BY (ISO 3166-2), or * for everywhere')"
					required />
				<NcTextField v-model="form.label" :label="t('absence', 'Name')" required />
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

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
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
import api from '../api.js'
import { store } from '../store.js'
import { formatDate, toIso } from '../utils/dates.js'
import { EVERYWHERE, regionFlag } from '../utils/regions.js'

export default {
	name: 'Holidays',
	components: { NcButton, NcCheckboxRadioSwitch, NcDateTimePickerNative, NcEmptyContent, NcModal, NcSelect, NcTextArea, NcTextField, Delete, Download, History, PartyPopper, Pencil, Plus, Upload, SkeletonList },
	data() {
		const y = new Date().getFullYear()
		const all = { id: '', label: t('absence', 'All regions') }
		return {
			loading: true,
			holidays: [],
			year: y,
			years: [y - 1, y, y + 1, y + 2],
			all,
			region: all,
			form: null,
			saving: false,
			importing: false,
			json: '',
			plan: null,
			importError: '',
			history: null,
			example: JSON.stringify([
				{ date: `${y + 1}-01-01`, region: 'DE', label: t('absence', 'New Year\'s Day') },
				{ date: `${y + 1}-12-24`, region: EVERYWHERE, label: t('absence', 'Company day off'), recurring: true },
			], null, 2),
		}
	},

	computed: {
		session() {
			return store.session
		},

		canEdit() {
			return store.session.canEditHolidays
		},

		regionOptions() {
			const codes = [...new Set(this.holidays.map((h) => h.region))].sort()
			return [this.all, ...codes.map((code) => ({ id: code, label: `${regionFlag(code)} ${this.regionName(code)}`.trim() }))]
		},

		shown() {
			return this.region.id === '' ? this.holidays : this.holidays.filter((h) => h.region === this.region.id)
		},
	},

	watch: {
		year() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,
		formatDate,
		regionFlag,
		regionName(code) {
			return code === EVERYWHERE ? t('absence', 'Everywhere') : code
		},

		async load() {
			this.loading = true
			try {
				this.holidays = await api.listHolidays(this.year)
			} catch {
				showError(t('absence', 'Could not load the public holidays'))
			} finally {
				this.loading = false
			}
		},

		edit(holiday) {
			this.form = holiday
				? { id: holiday.id, date: new Date(holiday.date + 'T00:00:00'), region: holiday.region, label: holiday.label, recurring: holiday.recurring }
				: { id: null, date: new Date(`${this.year}-01-01T00:00:00`), region: this.region.id, label: '', recurring: false }
		},

		async save() {
			this.saving = true
			const data = { date: toIso(this.form.date), region: this.form.region, label: this.form.label, recurring: this.form.recurring }
			try {
				await (this.form.id ? api.updateHoliday(this.form.id, data) : api.createHoliday(data))
				showSuccess(t('absence', 'Holiday saved'))
				this.form = null
				await this.load()
			} catch (e) {
				showError(e.response?.data?.message || t('absence', 'Could not save the holiday'))
			} finally {
				this.saving = false
			}
		},

		async remove(holiday) {
			try {
				await api.deleteHoliday(holiday.id)
				showSuccess(holiday.recurring
					? t('absence', '{name} deleted from every year', { name: holiday.label }, undefined, { escape: false })
					: t('absence', '{name} deleted', { name: holiday.label }, undefined, { escape: false }))
				await this.load()
			} catch (e) {
				showError(e.response?.data?.message || t('absence', 'Could not delete the holiday'))
			}
		},

		openImport() {
			this.importing = true
			this.plan = null
			this.importError = ''
		},

		async check() {
			this.saving = true
			this.importError = ''
			try {
				this.plan = await api.importHolidays(this.json, true)
			} catch (e) {
				this.importError = e.response?.data?.message || t('absence', 'Could not read the list')
			} finally {
				this.saving = false
			}
		},

		async runImport() {
			this.saving = true
			try {
				const result = await api.importHolidays(this.json, false)
				showSuccess(n('absence', '{count} holiday imported', '{count} holidays imported', result.new.length, { count: result.new.length }))
				this.importing = false
				this.json = ''
				await this.load()
			} catch (e) {
				this.importError = e.response?.data?.message || t('absence', 'Could not import the list')
			} finally {
				this.saving = false
			}
		},

		byline(holiday) {
			if (holiday.createdByName && holiday.updatedByName) {
				return t('absence', 'Added by {added} · changed by {changed}', { added: holiday.createdByName, changed: holiday.updatedByName }, undefined, { escape: false })
			}
			if (holiday.createdByName) {
				return t('absence', 'Added by {name}', { name: holiday.createdByName }, undefined, { escape: false })
			}
			return holiday.updatedByName ? t('absence', 'Changed by {name}', { name: holiday.updatedByName }, undefined, { escape: false }) : ''
		},

		async openHistory() {
			try {
				this.history = await api.holidayHistory()
			} catch {
				showError(t('absence', 'Could not load the history'))
			}
		},

		formatWhen(atom) {
			return new Date(atom).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
		},

		describeChange(change) {
			const who = change.actorName || t('absence', 'An administrator')
			const day = (d) => `${d.label} (${formatDate(d.date)}, ${this.regionName(d.region)})`
			const d = change.detail
			switch (change.action) {
				case 'added':
					return t('absence', '{who} added {day}', { who, day: day(d) }, undefined, { escape: false })
				case 'deleted':
					return t('absence', '{who} deleted {day}', { who, day: day(d) }, undefined, { escape: false })
				case 'changed':
					return t('absence', '{who} changed {before} to {after}', { who, before: day(d.before), after: day(d.after) }, undefined, { escape: false })
				case 'imported':
					return n('absence', '{who} imported {count} holiday', '{who} imported {count} holidays', d.count, { who, count: d.count }, { escape: false })
				case 'everyone_edits':
					return t('absence', '{who} let everyone edit public holidays', { who }, undefined, { escape: false })
				case 'hr_edits':
					return t('absence', '{who} made public holidays editable by HR only', { who }, undefined, { escape: false })
				default:
					return change.action
			}
		},

		download() {
			// The import format, so a year can be edited and imported again
			const list = this.shown.map(({ date, region, label, recurring }) => ({ date, region, label, recurring }))
			const link = document.createElement('a')
			link.href = URL.createObjectURL(new Blob([JSON.stringify(list, null, '\t')], { type: 'application/json' }))
			link.download = `holidays-${this.year}${this.region.id ? '-' + this.region.id : ''}.json`
			link.click()
			URL.revokeObjectURL(link.href)
		},
	},
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
