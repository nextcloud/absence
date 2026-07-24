<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Per-user settings that prefill the "Working days" field on new requests.
  - Appended to the built-in Availability page: working days come from the user's
  - Availability; the holiday country is guessed from their locale/phone. Both are
  - shown as feedback and can be overridden here.
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
			{{ t('absence', 'Public holidays for your location are not counted as working days. Choose your country and region so the right holidays apply.') }}
		</p>

		<div class="field">
			<label>{{ t('absence', 'Country') }}</label>
			<NcSelect
				v-model="country"
				:options="countryOptions"
				:loading="loadingCountries"
				label="label"
				:placeholder="countryPlaceholder"
				@update:modelValue="onCountryChange" />
		</div>
		<div v-if="regionOptions.length" class="field">
			<label>{{ t('absence', 'Region') }}</label>
			<NcSelect
				v-model="region"
				:options="regionOptions"
				label="label"
				:placeholder="t('absence', 'Whole country')" />
		</div>

		<NcButton variant="primary" :disabled="saving" @click="save">
			{{ t('absence', 'Save settings') }}
		</NcButton>
	</NcSettingsSection>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import api from '../../api.js'
import { parseWeekdays } from '../../utils/dates.js'
import { listCountries, listRegions } from '../../utils/holidays.js'

export default {
	name: 'PersonalSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcSelect, NcSettingsSection },
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
			country: null,
			region: null,
			countryOptions: [],
			regionOptions: [],
			loadingCountries: false,
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

		countryPlaceholder() {
			return this.config.holidayCountryDetected
				? t('absence', 'Detected: {country}', { country: this.config.holidayCountryDetected })
				: t('absence', 'Select a country…')
		},
	},

	async mounted() {
		this.loadingCountries = true
		try {
			this.countryOptions = await listCountries()
			this.country = this.countryOptions.find((c) => c.id === this.config.holidayCountry) || null
			await this.reloadRegions()
		} catch {
			showError(t('absence', 'Could not load the country list'))
		} finally {
			this.loadingCountries = false
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

		async onCountryChange() {
			this.region = null
			await this.reloadRegions()
		},

		async reloadRegions() {
			this.regionOptions = this.country ? await listRegions(this.country.id) : []
			this.region = this.regionOptions.find((r) => r.id === this.config.holidayRegion) || null
		},

		save() {
			this.saving = true
			// Only store an override when the choice differs from what was detected,
			// so future Availability / profile changes keep flowing through.
			const csv = this.weekdays.join(',')
			const detectedCsv = (this.config.workWeekdaysDetected || []).join(',')
			const weekdaysValue = (this.config.availabilitySet && csv === detectedCsv) ? '' : csv

			const countryCode = this.country ? this.country.id : ''
			const countryValue = countryCode === (this.config.holidayCountryDetected || '') ? '' : countryCode

			const values = {
				work_weekdays: weekdaysValue,
				holiday_country: countryValue,
				holiday_region: this.region ? this.region.id : '',
			}
			api.updatePersonalConfig(values)
				.then((updated) => {
					this.config = updated
					showSuccess(t('absence', 'Settings saved'))
				})
				.catch(() => showError(t('absence', 'Could not save settings')))
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
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 320px;
	margin-bottom: 12px;

	label {
		font-weight: 600;
		font-size: 0.85rem;
	}
}
</style>
