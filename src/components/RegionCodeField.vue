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
				v-model="code"
				:label="t('absence', 'My region code')"
				:error="code !== '' && !codeValid"
				:helperText="code !== '' && !codeValid ? t('absence', 'Use a country like DE or a region like DE-BY') : ''" />
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

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import api from '../api.js'
import { store } from '../store.js'
import { parseWeekdays } from '../utils/dates.js'
import { compareHolidays, formatRegionCode, groupByYear, hasHolidays, parseRegionCode } from '../utils/holidays.js'
import { regionCountryName, regionFlag } from '../utils/regions.js'

export default {
	name: 'RegionCodeField',
	components: { NcButton, NcDialog, NcNoteCard, NcTextField },
	props: {
		/** The saved code, e.g. `DE-BY`, empty when none */
		savedCode: { type: String, required: true },
	},

	emits: ['saved'],

	data() {
		return {
			code: this.savedCode,
			knownCodes: [],
			// What a changed code would change, shown before saving it
			change: null,
			saving: false,
		}
	},

	computed: {
		codeValid() {
			return parseRegionCode(this.code) !== null
		},

		/** The days lost and gained, each split by year */
		changeColumns() {
			return [
				{ key: 'lost', title: t('absence', 'No longer days off'), count: `−${this.change.lost.length}`, years: groupByYear(this.change.lost) },
				{ key: 'gained', title: t('absence', 'New days off'), count: `+${this.change.gained.length}`, years: groupByYear(this.change.gained) },
			]
		},

		workingWeekdays() {
			return parseWeekdays(store.session.workWeekdays)
		},

		changeButtons() {
			return [
				{ label: t('absence', 'Cancel'), callback: () => {} },
				{ label: t('absence', 'Change region'), variant: 'primary', callback: () => this.store() },
			]
		},
	},

	watch: {
		savedCode(code) {
			this.code = code
		},
	},

	async mounted() {
		try {
			this.knownCodes = (await api.listHolidayRegions()).filter((known) => known !== '*')
		} catch {
			// Only the hint is missing
		}
	},

	methods: {
		t,
		n,
		regionFlag,

		describeCode(code) {
			if (!code) {
				return t('absence', 'No region code')
			}
			const country = regionCountryName(code)
			return country ? `${regionFlag(code)} ${code} · ${country}` : code
		},

		shortDate(iso) {
			return new Date(iso + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
		},

		isWorkingDay(iso) {
			const day = new Date(iso + 'T00:00:00').getDay()
			return this.workingWeekdays.has(day === 0 ? 7 : day)
		},

		async save() {
			const parsed = parseRegionCode(this.code)
			const code = formatRegionCode(parsed.country, parsed.region)
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
			const { country, region } = parseRegionCode(this.code)
			api.updatePersonalConfig({ holiday_country: country, holiday_region: region })
				.then((updated) => {
					this.$emit('saved', formatRegionCode(updated.holidayCountry, updated.holidayRegion))
					showSuccess(t('absence', 'Region code saved'))
				})
				.catch((e) => showError(e.response?.data?.message || t('absence', 'Could not save the region code')))
				.finally(() => {
					this.saving = false
				})
		},
	},
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
