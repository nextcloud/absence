<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Someone's profile (§15.2): their leave, region code and holidays, contract and
  - sick days. The person, their manager and HR see it; HR edits the contract.
-->
<template>
	<div class="page page--narrow page--roomy">
		<header class="page__header page__header--center">
			<div class="profile__who">
				<NcAvatar :user="profileUid" :size="44" :disableMenu="true" />
				<div>
					<h2 class="page__title">
						{{ profile ? profile.displayName : '' }}
					</h2>
					<p v-if="profile && !error" class="profile__sub">
						<template v-if="profile.manager">
							{{ t('absence', 'Reports to {name}', { name: profile.manager.displayName }, undefined, { escape: false }) }}
						</template>
						<template v-else>
							{{ t('absence', 'No manager: requests go to HR') }}
						</template>
						<template v-if="profile.contacts">
							·
							<a :href="contactsUrl" target="_blank" rel="noopener noreferrer">{{ t('absence', 'See team relations') }} ↗</a>
						</template>
					</p>
				</div>
			</div>
			<p v-if="profile && profile.timezone" class="profile__sub">
				{{ t('absence', 'Time zone: {zone}', { zone: profile.timezone }) }}
				<template v-if="profile.self">
					· <a :href="settingsUrl" target="_blank" rel="noopener noreferrer">{{ t('absence', 'Change') }} ↗</a>
				</template>
			</p>
		</header>

		<EntitlementDialog
			v-if="editingDays && profile"
			:uid="profileUid"
			:displayName="profile.displayName"
			:row="editingDays"
			:year="year"
			@close="editingDays = null"
			@saved="onDaysSaved" />

		<NcEmptyContent v-if="error" :name="error" />
		<SkeletonList v-else-if="!profile" />
		<div v-else class="profile__grid">
			<section class="profile__card">
				<h3>{{ t('absence', 'Vacation {year}', { year: profile.holidays.year }) }}</h3>
				<BalanceCard v-for="row in balances" :key="row.typeId" :row="row" />
				<NcButton
					v-if="profile.canEditContract && balances.length"
					class="profile__edit"
					@click="editingDays = balances[0] ?? null">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('absence', 'Edit vacation days') }}
				</NcButton>
				<p v-if="profile.nextLeave" class="profile__note">
					{{ n('absence', 'Next leave: {range}, {count} working day', 'Next leave: {range}, {count} working days', profile.nextLeave.workingDays, { range: formatRange(profile.nextLeave.startDate, profile.nextLeave.endDate), count: profile.nextLeave.workingDays }) }}
				</p>
			</section>

			<section class="profile__card">
				<h3>{{ t('absence', 'Public holidays') }}</h3>
				<RegionCodeField v-if="profile.self" :savedCode="profile.regionCode" @saved="onRegionSaved" />
				<p v-else class="profile__value">
					{{ profile.regionCode ? `${regionFlag(profile.regionCode)} ${profile.regionCode}` : t('absence', 'No region code yet') }}
				</p>
				<p class="profile__figure">
					{{ n('absence', '{count} holiday in {year}', '{count} holidays in {year}', profile.holidays.count, { count: profile.holidays.count, year: profile.holidays.year }) }}
					· {{ n('absence', '{count} still to come', '{count} still to come', profile.holidays.remaining, { count: profile.holidays.remaining }) }}
				</p>
				<p v-if="profile.holidays.upcoming.length" class="profile__note">
					{{ t('absence', 'Next: {days}', { days: profile.holidays.upcoming.map((day) => `${day.label}, ${formatDate(day.date)}`).join(' · ') }, undefined, { escape: false }) }}
				</p>
				<router-link :to="{ name: 'holidays' }">
					{{ t('absence', 'See the list') }}
				</router-link>
			</section>

			<section class="profile__card profile__card--wide">
				<h3>{{ t('absence', 'Contract') }}</h3>
				<ContractEditor
					v-if="profile.canEditContract"
					:key="contractKey"
					:uid="profileUid"
					:contract="profile.contract"
					@saved="onContractSaved" />
				<template v-else>
					<template v-if="current">
						<p class="profile__value">
							{{ weekdayNames(current.weekdays) }}<template v-if="current.percent !== null">
								· {{ t('absence', '{percent}%', { percent: current.percent }) }}
							</template>
						</p>
						<p class="profile__note">
							{{ t('absence', 'Since {date}', { date: formatDate(current.from) }) }}
						</p>
					</template>
					<p v-else class="profile__note">
						{{ t('absence', 'No working pattern yet: Monday to Friday is assumed.') }}
					</p>
					<p v-if="profile.contract.start" class="profile__note">
						{{ t('absence', 'Started on {date}', { date: formatDate(profile.contract.start) }) }}<template v-if="profile.contract.end">
							· {{ t('absence', 'Leaves on {date}', { date: formatDate(profile.contract.end) }) }}
						</template>
					</p>
				</template>
				<p class="profile__note">
					{{ t('absence', 'Set by HR. Visible to this person, their manager and HR.') }}
				</p>
			</section>

			<section class="profile__card">
				<h3>{{ t('absence', 'Sick days {year}', { year: profile.sick.year }) }}</h3>
				<p class="profile__figure">
					{{ n('absence', '{count} day', '{count} days', profile.sick.days, { count: profile.sick.days }) }}
					· {{ n('absence', '{count} time', '{count} times', profile.sick.times, { count: profile.sick.times }) }}
				</p>
			</section>

			<section v-if="profile.history" class="profile__card">
				<h3>{{ t('absence', 'History') }}</h3>
				<ul v-if="profile.history.length" class="profile__history">
					<li v-for="(change, index) in profile.history" :key="index">
						<span class="profile__note">{{ formatDate(change.createdAt.slice(0, 10)) }} · {{ change.actorName || t('absence', 'Import') }}</span>
						{{ describe(change) }}
					</li>
				</ul>
				<p v-else class="profile__note">
					{{ t('absence', 'No changes yet.') }}
				</p>
			</section>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { BalanceRow, ContractPeriod, Profile, ProfileChange } from '../types/index.ts'

import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, onMounted, ref, watch } from 'vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import BalanceCard from '../components/BalanceCard.vue'
import ContractEditor from '../components/ContractEditor.vue'
import EntitlementDialog from '../components/EntitlementDialog.vue'
import RegionCodeField from '../components/RegionCodeField.vue'
import SkeletonList from '../components/SkeletonList.vue'
import api from '../services/api.ts'
import { useSessionStore } from '../stores/session.ts'
import { formatDate, formatRange, toIso } from '../utils/dates.ts'
import { serverMessage } from '../utils/errors.ts'
import { latestOnly } from '../utils/latest.ts'
import { regionFlag } from '../utils/regions.ts'
import { dayNames } from '../utils/weekdays.ts'

const props = defineProps<{
	/** Someone else's profile; the viewer's own without it */
	uid?: string
}>()

const sessionStore = useSessionStore()

/** Whose profile this is */
const profileUid = computed(() => props.uid ?? sessionStore.session.uid ?? '')

// Going from one profile to the next: the answer for the previous person
// must not land on the current one.
const startLoad = latestOnly()

const profile = ref<Profile | null>(null)
const balances = ref<BalanceRow[]>([])
const error = ref<string | null>(null)
const contractKey = ref(0)
// The balance row HR edits the vacation days of
const editingDays = ref<BalanceRow | null>(null)
const year = new Date().getFullYear()

const contactsUrl = computed(() => generateUrl('/apps/contacts/u/{uid}', { uid: profileUid.value }))

const settingsUrl = computed(() => generateUrl('/settings/user'))

/** The working pattern of today */
const current = computed<ContractPeriod | null>(() => {
	if (!profile.value) {
		return null
	}
	const today = toIso(new Date())
	return [...profile.value.contract.periods].reverse().find((period) => period.from <= today) ?? null
})

watch(profileUid, () => {
	// The row belongs to the previous person: saving it would write to this one
	editingDays.value = null
	load()
})

onMounted(() => {
	load()
})

/**
 * Load the profile and the vacation balance of the person
 */
async function load(): Promise<void> {
	const isLatest = startLoad()
	profile.value = null
	error.value = null
	try {
		const [loaded, balance] = await Promise.all([
			api.getProfile(profileUid.value),
			api.getEmployeeBalance(profileUid.value, year),
		])
		if (!isLatest()) {
			return
		}
		balances.value = balance.balances.filter((row) => row.countsAgainstBalance && row.typeKey === 'annual')
		profile.value = loaded
	} catch (e) {
		if (!isLatest()) {
			return
		}
		error.value = serverMessage(e) || t('absence', 'Could not load this profile')
	}
}

/**
 * The short names of a working pattern's days
 *
 * @param weekdays ISO weekdays, Mon=1
 */
function weekdayNames(weekdays: number[]): string {
	const names = dayNames()
	return weekdays.map((day) => names[day - 1]).join(', ')
}

/**
 * One line of the history, in words
 *
 * @param change the change to the contract or the region code
 */
function describe(change: ProfileChange): string {
	if (change.field === 'region') {
		return t('absence', 'Region code {before} → {after}', { before: change.before || t('absence', 'none'), after: change.after || t('absence', 'none') })
	}
	const latest = change.after.periods.at(-1)
	if (!latest) {
		return t('absence', 'Contract changed')
	}
	const days = weekdayNames(latest.weekdays)
	const date = formatDate(latest.from)
	return latest.percent === null
		? t('absence', 'Contract: {days} from {date}', { days, date })
		: t('absence', 'Contract: {days}, {percent}% from {date}', { days, percent: latest.percent, date })
}

/**
 * The person saved another region code: the holidays and the session follow
 *
 * @param code the saved code, such as `DE-BY`
 */
function onRegionSaved(code: string): void {
	load()
	sessionStore.session.holidayCountry = code.split('-')[0] ?? ''
	sessionStore.session.holidayRegion = code.split('-')[1] ?? ''
}

/**
 * HR saved the vacation days: close the dialog and load the new balance
 */
function onDaysSaved(): void {
	editingDays.value = null
	load()
}

/**
 * HR saved the contract: the editor starts again from what was saved
 */
function onContractSaved(): void {
	contractKey.value++
	load()
}
</script>

<style scoped lang="scss">
.profile {
	&__who {
		display: flex;
		align-items: center;
		gap: 14px;
	}

	&__sub {
		color: var(--color-text-maxcontrast);
		margin: 0;

		a {
			color: var(--color-primary-element);
			font-weight: 600;
		}
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
		gap: 16px;
	}

	&__card {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		padding: 16px 18px;

		h3 {
			margin: 0 0 10px;
			font-size: 1.1rem;
			font-weight: 600;
		}

		&--wide {
			grid-column: 1 / -1;
		}
	}

	&__value {
		font-weight: 600;
		margin: 4px 0;
	}

	&__figure {
		font-size: 1.1rem;
		margin: 12px 0 4px;
	}

	&__edit {
		margin-top: 8px;
	}

	&__note {
		color: var(--color-text-maxcontrast);
		margin: 6px 0;
	}

	&__history {
		margin: 0;
		padding: 0;
		list-style: none;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
}
</style>
