<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Per-user settings that prefill the "Working days" field on new requests.
  - Appended to the built-in Availability page: working days come from the user's
  - Availability and can be overridden here. The holiday region code is set here by
  - the person themselves (§7.2), never guessed.
-->
<template>
	<NcSettingsSection
		:name="t('absence', 'Absence')"
		:description="t('absence', 'These settings prefill the “Working days” field when you request time off. You can always change the number on the request itself.')">
		<h3 class="subheading">
			{{ t('absence', 'Working days') }}
		</h3>

		<NcNoteCard v-if="config.availabilitySet" type="success">
			{{ t('absence', 'Detected from your Availability: {days}.', { days: detectedWeekdayLabels }) }}
		</NcNoteCard>
		<NcNoteCard v-else type="info">
			{{ t('absence', 'You have not set your working hours yet. Set your Availability so your working days are filled in automatically — until then Monday–Friday is assumed.') }}
		</NcNoteCard>

		<div class="weekdays">
			<NcCheckboxRadioSwitch
				v-for="d in weekdayList"
				:key="d.iso"
				:modelValue="weekdays.includes(d.iso)"
				:disabled="weekdaysDisabled"
				@update:modelValue="(v) => toggleWeekday(d.iso, v)">
				{{ d.label }}
			</NcCheckboxRadioSwitch>
		</div>

		<div class="actions-row">
			<NcButton variant="secondary" href="#settings-personal-availability">
				{{ config.availabilitySet ? t('absence', 'Change availability') : t('absence', 'Set availability') }}
			</NcButton>
			<NcButton
				v-if="config.availabilitySet && !overriding"
				variant="tertiary"
				@click="overriding = true">
				{{ t('absence', 'Override') }}
			</NcButton>
			<NcButton
				v-else-if="config.availabilitySet && overriding"
				variant="tertiary"
				@click="cancelOverride">
				{{ t('absence', 'Cancel override') }}
			</NcButton>
		</div>

		<h3 class="subheading">
			{{ t('absence', 'Public holidays') }}
		</h3>
		<p class="hint">
			{{ t('absence', 'Your public holidays are not counted as working days. Enter the region code of where you work: a country like DE, or a region like DE-BY. The holidays of each code are on the Public holidays page.') }}
		</p>

		<div class="field">
			<NcTextField
				v-model="code"
				:label="t('absence', 'Holiday region code')"
				:error="code !== '' && !codeValid"
				:helperText="code !== '' && !codeValid ? t('absence', 'Use a country like DE or a region like DE-BY') : ''" />
		</div>
		<p v-if="knownCodes.length" class="hint">
			{{ t('absence', 'Codes with holidays: {codes}', { codes: knownCodes.map((code) => `${regionFlag(code)} ${code}`).join(', ') }, undefined, { escape: false }) }}
		</p>

		<NcButton variant="primary" :disabled="saving || !codeValid" @click="save">
			{{ t('absence', 'Save settings') }}
		</NcButton>

		<NcDialog
			:open="change !== null"
			:name="t('absence', 'Change your holiday region?')"
			:buttons="changeButtons"
			@update:open="change = null">
			<template v-if="change">
				<NcNoteCard v-if="change.noHolidays" type="warning">
					{{ t('absence', 'No public holidays are defined for {code}: only the days for everywhere apply. Check the code, or get its holidays added on the Public holidays page.', { code: change.code }) }}
				</NcNoteCard>
				<p>{{ t('absence', 'From {before} to {after}, this year and next:', { before: change.before || t('absence', 'none'), after: change.code || t('absence', 'none') }) }}</p>
				<template v-if="change.lost.length">
					<h4 class="change__title">
						{{ t('absence', 'No longer a day off') }}
					</h4>
					<ul class="change__list">
						<li v-for="day in change.lost" :key="'lost-' + day.date">
							{{ formatDate(day.date) }} · {{ day.label }}
						</li>
					</ul>
				</template>
				<template v-if="change.gained.length">
					<h4 class="change__title">
						{{ t('absence', 'New days off') }}
					</h4>
					<ul class="change__list">
						<li v-for="day in change.gained" :key="'gained-' + day.date">
							{{ formatDate(day.date) }} · {{ day.label }}
						</li>
					</ul>
				</template>
				<p class="hint">
					{{ n('absence', '{count} day stays the same.', '{count} days stay the same.', change.kept, { count: change.kept }) }}
				</p>
			</template>
		</NcDialog>
	</NcSettingsSection>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import api from '../../api.js'
import { formatDate, parseWeekdays } from '../../utils/dates.js'
import { compareHolidays, formatRegionCode, hasHolidays, parseRegionCode } from '../../utils/holidays.js'
import { regionFlag } from '../../utils/regions.js'

export default {
	name: 'PersonalSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard, NcSettingsSection, NcTextField },
	data() {
		const config = loadState('absence', 'personalConfig')
		return {
			config,
			// ISO weekdays (Mon=1..Sun=7) in their real week order.
			weekdayList: [
				{ iso: 1, label: t('absence', 'Monday') },
				{ iso: 2, label: t('absence', 'Tuesday') },
				{ iso: 3, label: t('absence', 'Wednesday') },
				{ iso: 4, label: t('absence', 'Thursday') },
				{ iso: 5, label: t('absence', 'Friday') },
				{ iso: 6, label: t('absence', 'Saturday') },
				{ iso: 7, label: t('absence', 'Sunday') },
			],

			weekdays: [...parseWeekdays(config.workWeekdays)].sort(),
			// Editing is on when there is nothing to detect, or the user already has
			// a manual override; otherwise the detected days show as read-only.
			overriding: !config.availabilitySet || config.workWeekdaysOverride !== '',
			code: formatRegionCode(config.holidayCountry, config.holidayRegion),
			knownCodes: [],
			// What a changed code would change, shown before saving it
			change: null,
			saving: false,
		}
	},

	computed: {
		weekdaysDisabled() {
			return this.config.availabilitySet && !this.overriding
		},

		detectedWeekdayLabels() {
			return (this.config.workWeekdaysDetected || [])
				.map((iso) => this.weekdayList.find((d) => d.iso === iso)?.label)
				.filter(Boolean)
				.join(', ')
		},

		codeValid() {
			return parseRegionCode(this.code) !== null
		},

		savedCode() {
			return formatRegionCode(this.config.holidayCountry, this.config.holidayRegion)
		},

		changeButtons() {
			return [
				{ label: t('absence', 'Cancel'), callback: () => {} },
				{ label: t('absence', 'Change region'), variant: 'primary', callback: () => this.store() },
			]
		},
	},

	async mounted() {
		try {
			this.knownCodes = (await api.listHolidayRegions()).filter((code) => code !== '*')
		} catch {
			// Only the hint is missing
		}
	},

	methods: {
		t,
		toggleWeekday(iso, on) {
			const set = new Set(this.weekdays)
			if (on) {
				set.add(iso)
			} else {
				set.delete(iso)
			}
			this.weekdays = [...set].sort()
		},

		cancelOverride() {
			// Drop back to the days detected from Availability.
			this.overriding = false
			this.weekdays = [...(this.config.workWeekdaysDetected || [])].sort()
		},

		n,
		formatDate,
		regionFlag,

		/** A changed code first shows what it changes, the rest saves right away. */
		async save() {
			const parsed = parseRegionCode(this.code)
			const code = formatRegionCode(parsed.country, parsed.region)
			if (code === this.savedCode) {
				this.store()
				return
			}
			this.saving = true
			try {
				const year = new Date().getFullYear()
				const [before, after] = await Promise.all([
					api.listApplyingHolidays(this.savedCode, `${year}-01-01`, `${year + 1}-12-31`),
					api.listApplyingHolidays(code, `${year}-01-01`, `${year + 1}-12-31`),
				])
				this.change = {
					before: this.savedCode,
					code,
					noHolidays: code !== '' && !hasHolidays(code, this.knownCodes),
					...compareHolidays(before, after),
				}
			} catch {
				showError(t('absence', 'Could not load the holidays of {code}', { code }))
			} finally {
				this.saving = false
			}
		},

		store() {
			this.saving = true
			const submitted = this.code
			const { country, region } = parseRegionCode(submitted)
			// Only store an override when the choice differs from what was detected,
			// so future Availability / profile changes keep flowing through.
			const csv = this.weekdays.join(',')
			const detectedCsv = (this.config.workWeekdaysDetected || []).join(',')
			const weekdaysValue = (this.config.availabilitySet && csv === detectedCsv) ? '' : csv

			const values = {
				work_weekdays: weekdaysValue,
				holiday_country: country,
				holiday_region: region,
			}
			api.updatePersonalConfig(values)
				.then((updated) => {
					this.config = updated
					// `de-by` reads `DE-BY` once saved, unless it was edited meanwhile
					if (this.code === submitted) {
						this.code = this.savedCode
					}
					showSuccess(t('absence', 'Settings saved'))
				})
				.catch((e) => showError(e.response?.data?.message || t('absence', 'Could not save settings')))
				.finally(() => {
					this.saving = false
				})
		},
	},
}
</script>

<style scoped lang="scss">
.subheading {
	font-weight: 600;
	margin-bottom: 8px;

	&:not(:first-child) {
		margin-top: 24px;
	}
}

.hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	margin: 8px 0;
}

.weekdays {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 20px;
	margin: 8px 0;
}

.actions-row {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.field {
	max-width: 320px;
	margin-bottom: 12px;
}

.change {
	&__title {
		margin: 12px 0 4px;
		font-weight: 600;
	}

	&__list {
		margin: 0;
		padding-inline-start: 20px;
		list-style: disc;
	}
}
</style>
