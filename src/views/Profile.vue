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
				<NcAvatar :user="uid" :size="44" :disableMenu="true" />
				<div>
					<h2 class="page__title">
						{{ profile ? profile.displayName : '' }}
					</h2>
					<p v-if="profile" class="profile__sub">
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

		<SkeletonList v-if="!profile" />
		<NcEmptyContent v-else-if="error" :name="error" />
		<div v-else class="profile__grid">
			<section class="profile__card">
				<h3>{{ t('absence', 'Vacation {year}', { year: profile.holidays.year }) }}</h3>
				<BalanceCard v-for="row in balances" :key="row.typeId" :row="row" />
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
					:uid="uid"
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

<script>
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import BalanceCard from '../components/BalanceCard.vue'
import ContractEditor from '../components/ContractEditor.vue'
import RegionCodeField from '../components/RegionCodeField.vue'
import SkeletonList from '../components/SkeletonList.vue'
import api from '../api.js'
import { store } from '../store.js'
import { formatDate, formatRange, toIso } from '../utils/dates.js'
import { regionFlag } from '../utils/regions.js'
import { dayNames } from '../utils/weekdays.js'

export default {
	name: 'Profile',
	components: { BalanceCard, ContractEditor, NcAvatar, NcEmptyContent, RegionCodeField, SkeletonList },
	props: {
		/** Someone else's profile; the viewer's own without it */
		uid: { type: String, default: () => store.session.uid },
	},

	data() {
		return {
			profile: null,
			balances: [],
			error: null,
			contractKey: 0,
		}
	},

	computed: {
		contactsUrl() {
			return generateUrl('/apps/contacts/u/{uid}', { uid: this.uid })
		},

		settingsUrl() {
			return generateUrl('/settings/user')
		},

		/** The working pattern of today */
		current() {
			const today = toIso(new Date())
			return [...this.profile.contract.periods].reverse().find((period) => period.from <= today) ?? null
		},
	},

	watch: {
		uid: 'load',
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,
		formatDate,
		formatRange,
		regionFlag,

		async load() {
			this.profile = null
			this.error = null
			try {
				const [profile, balance] = await Promise.all([
					api.getProfile(this.uid),
					api.getEmployeeBalance(this.uid, new Date().getFullYear()),
				])
				this.balances = balance.balances.filter((row) => row.countsAgainstBalance && row.typeKey === 'annual')
				this.profile = profile
			} catch (e) {
				this.error = e.response?.data?.message || t('absence', 'Could not load this profile')
				this.profile = {}
			}
		},

		weekdayNames(weekdays) {
			const names = dayNames()
			return weekdays.map((day) => names[day - 1]).join(', ')
		},

		describe(change) {
			if (change.field === 'region') {
				return t('absence', 'Region code {before} → {after}', { before: change.before || t('absence', 'none'), after: change.after || t('absence', 'none') })
			}
			const latest = change.after.periods.at(-1)
			return latest
				? t('absence', 'Contract: {days}, {percent} from {date}', {
						days: this.weekdayNames(latest.weekdays),
						percent: latest.percent === null ? '' : `${latest.percent}%`,
						date: formatDate(latest.from),
					})
				: t('absence', 'Contract changed')
		},

		onRegionSaved(code) {
			this.load()
			store.session.holidayCountry = code.split('-')[0] ?? ''
			store.session.holidayRegion = code.split('-')[1] ?? ''
		},

		onContractSaved() {
			this.contractKey++
			this.load()
		},
	},
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
