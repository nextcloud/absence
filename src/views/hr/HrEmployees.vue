<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - HR's list of every employee (§13): region code, working days, contract,
  - manager, and the days of the selected year, which HR can also edit as JSON.
  - Managers are kept in Contacts and user management, never here.
-->
<template>
	<div class="page">
		<header class="page__header">
			<div>
				<h2 class="page__title">
					{{ t('absence', 'Employees') }}
				</h2>
				<p class="employees__sub">
					{{ employeesGroup
						? t('absence', 'Members of the group {group}, {year}', { group: employeesGroup, year }, undefined, { escape: false })
						: t('absence', 'Everyone except guest accounts, {year}', { year }) }}
				</p>
			</div>
			<div class="page__tools">
				<NcSelect
					v-model="year"
					:options="years"
					:clearable="false"
					:aria-label-combobox="t('absence', 'Year')" />
				<NcButton v-if="withoutRegion" @click="reminding = true">
					<template #icon>
						<BellRingOutline :size="20" />
					</template>
					{{ n('absence', 'Remind {count} without a complete region code', 'Remind {count} without a complete region code', withoutRegion, { count: withoutRegion }) }}
				</NcButton>
				<NcButton :href="exportUrl">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('absence', 'Export') }}
				</NcButton>
				<NcButton @click="editingJson = true">
					<template #icon>
						<CodeBraces :size="20" />
					</template>
					{{ t('absence', 'Edit as JSON') }}
				</NcButton>
				<a
					v-if="contacts"
					class="employees__link"
					:href="contactsUrl"
					target="_blank"
					rel="noopener noreferrer">{{ t('absence', 'Org chart in Contacts') }} ↗</a>
			</div>
		</header>

		<div class="employees__filters">
			<NcTextField
				v-model="search"
				:label="t('absence', 'Search')"
				class="page__search">
				<template #icon>
					<Magnify :size="18" />
				</template>
			</NcTextField>
			<div class="employees__chips" role="group" :aria-label="t('absence', 'Show')">
				<NcButton
					v-for="option in filters"
					:key="option.id"
					size="small"
					:pressed="filter === option.id"
					@update:pressed="filter = option.id">
					{{ option.label }}
				</NcButton>
			</div>
		</div>

		<SkeletonList v-if="loading" :rows="6" />

		<template v-else>
			<div class="table-wrap">
				<table class="tbl employees__table">
					<thead>
						<tr>
							<th>{{ t('absence', 'Name') }}</th>
							<th>{{ t('absence', 'Region') }}</th>
							<th>{{ t('absence', 'Works') }}</th>
							<th class="num">
								{{ t('absence', 'Contract') }}
							</th>
							<th>{{ t('absence', 'Since') }}</th>
							<th>{{ t('absence', 'Manager') }}</th>
							<th class="num">
								{{ t('absence', 'Days') }}
							</th>
							<th class="num">
								{{ t('absence', 'Taken') }}
							</th>
							<th class="num">
								{{ t('absence', 'Left') }}
							</th>
							<th class="num">
								{{ t('absence', 'Sick') }}
							</th>
							<th>
								<span class="hidden-visually">{{ t('absence', 'Actions') }}</span>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in shown" :key="row.uid">
							<td>
								<router-link class="employees__name" :to="{ name: 'profile-of', params: { uid: row.uid } }">
									<NcAvatar
										:user="row.uid"
										:displayName="row.displayName"
										:size="28"
										disableMenu
										hideStatus />
									{{ row.displayName }}
								</router-link>
							</td>
							<td>
								<template v-if="row.regionCode">
									<span aria-hidden="true">{{ regionFlag(row.regionCode) }}</span> {{ row.regionCode }}
									<NcChip
										v-if="row.regionIncomplete"
										variant="warning"
										noClose
										:text="t('absence', 'Region needed')" />
								</template>
								<NcChip
									v-else
									variant="warning"
									noClose
									:text="t('absence', 'No region code')" />
							</td>
							<td>
								<template v-if="row.current">
									{{ formatWeekdays(row.current.weekdays) }}
								</template>
								<NcChip
									v-else-if="row.missing.includes('contract')"
									variant="warning"
									noClose
									:text="t('absence', 'No contract data')" />
							</td>
							<td class="num">
								<template v-if="row.current && row.current.percent !== null">
									{{ t('absence', '{percent}%', { percent: row.current.percent }) }}
								</template>
							</td>
							<td>
								{{ since(row.contract.start) }}
								<span v-if="row.contract.end" class="employees__muted">
									{{ t('absence', 'until {date}', { date: formatDate(row.contract.end) }) }}
								</span>
							</td>
							<td>
								<template v-if="row.manager">
									{{ row.manager.displayName }}
								</template>
								<NcChip
									v-else
									variant="warning"
									noClose
									:text="t('absence', 'No manager')" />
							</td>
							<td class="num">
								{{ fmt(row.annual?.entitlement) }}
							</td>
							<td class="num">
								{{ fmt(row.annual?.used) }}
							</td>
							<td class="num lead" :class="leftClass(row.annual)">
								{{ fmt(row.annual?.remaining) }}
							</td>
							<td class="num">
								{{ fmt(row.sickDays) }}
							</td>
							<td>
								<NcActions :aria-label="t('absence', 'Actions for {name}', { name: row.displayName }, undefined, { escape: false })">
									<NcActionButton
										v-for="balance in row.balances"
										:key="balance.typeId"
										@click="editing = { row, balance }">
										<template #icon>
											<Pencil :size="20" />
										</template>
										{{ t('absence', 'Edit entitlement: {type}', { type: balance.typeLabel }, undefined, { escape: false }) }}
									</NcActionButton>
								</NcActions>
							</td>
						</tr>
					</tbody>
				</table>
				<NcEmptyContent
					v-if="!filtered.length"
					:name="t('absence', 'No matches')"
					:description="t('absence', 'Nobody matches this search and filter.')">
					<template #icon>
						<AccountGroup :size="20" />
					</template>
				</NcEmptyContent>
			</div>
			<NcButton
				v-if="filtered.length > shown.length"
				class="employees__more"
				@click="limit += PAGE">
				{{ t('absence', 'Load more') }}
			</NcButton>
			<p class="employees__note">
				{{ t('absence', 'Days are the allowance HR sets for each person, plus carry-over and adjustments. Without a manager, a person\'s requests go straight to HR.') }}
			</p>
		</template>

		<EntitlementDialog
			v-if="editing"
			:uid="editing.row.uid"
			:displayName="editing.row.displayName"
			:row="editing.balance"
			:year="year"
			@close="editing = null"
			@saved="onSaved" />
		<EmployeesJsonDialog
			v-if="editingJson"
			@close="editingJson = false"
			@saved="onSaved" />
		<RegionReminderDialog v-if="reminding" @close="reminding = false" />
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import BellRingOutline from 'vue-material-design-icons/BellRingOutline.vue'
import CodeBraces from 'vue-material-design-icons/CodeBraces.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import EmployeesJsonDialog from '../../components/EmployeesJsonDialog.vue'
import EntitlementDialog from '../../components/EntitlementDialog.vue'
import RegionReminderDialog from '../../components/RegionReminderDialog.vue'
import SkeletonList from '../../components/SkeletonList.vue'
import api from '../../api.js'
import { formatDate } from '../../utils/dates.js'
import { regionFlag } from '../../utils/regions.js'
import { formatWeekdays } from '../../utils/weekdays.js'

/** Rows shown at first and added by "Load more" */
const PAGE = 50

// Below this share of the entitlement, the days left are nearly spent.
const LOW_BALANCE_RATIO = 0.2

export default {
	name: 'HrEmployees',
	components: { AccountGroup, BellRingOutline, CodeBraces, Download, EmployeesJsonDialog, EntitlementDialog, Magnify, NcActionButton, NcActions, NcAvatar, NcButton, NcChip, NcEmptyContent, NcSelect, NcTextField, Pencil, RegionReminderDialog, SkeletonList },
	data() {
		const y = new Date().getFullYear()
		return {
			PAGE,
			loading: true,
			rows: [],
			employeesGroup: '',
			contacts: false,
			search: '',
			filter: 'all',
			limit: PAGE,
			year: y,
			years: [y - 1, y, y + 1],
			editing: null,
			editingJson: false,
			reminding: false,
		}
	},

	computed: {
		withoutRegion() {
			return this.rows.filter((row) => row.missing.includes('region')).length
		},

		filters() {
			const count = (test) => this.rows.filter(test).length
			return [
				{ id: 'all', label: t('absence', 'All {count}', { count: this.rows.length }) },
				{ id: 'missing', label: `⚠️ ${t('absence', 'Missing data {count}', { count: count(this.matches.missing) })}` },
				{ id: 'partTime', label: t('absence', 'Part-time {count}', { count: count(this.matches.partTime) }) },
				{ id: 'startsOrLeaves', label: t('absence', 'Starts or leaves in {year} {count}', { year: this.year, count: count(this.matches.startsOrLeaves) }) },
			]
		},

		matches() {
			return {
				all: () => true,
				missing: (row) => row.missing.length > 0,
				partTime: (row) => row.partTime,
				startsOrLeaves: (row) => row.startsOrLeaves,
			}
		},

		filtered() {
			const q = this.search.trim().toLowerCase()
			return this.rows.filter((row) => this.matches[this.filter](row)
				&& (!q || row.displayName.toLowerCase().includes(q) || row.uid.toLowerCase().includes(q)))
		},

		shown() {
			return this.filtered.slice(0, this.limit)
		},

		exportUrl() {
			return api.exportBalancesUrl(this.year)
		},

		contactsUrl() {
			return generateUrl('/apps/contacts/')
		},
	},

	watch: {
		year() {
			this.reload()
		},

		search() {
			this.limit = PAGE
		},

		filter() {
			this.limit = PAGE
		},
	},

	mounted() {
		this.reload()
	},

	methods: {
		t,
		n,
		formatDate,
		formatWeekdays,
		regionFlag,

		fmt(v) {
			return v === null || v === undefined ? '' : Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 })
		},

		/**
		 * The year someone started, or the day when it is in the selected year.
		 *
		 * @param {string|null} start YYYY-MM-DD
		 * @return {string}
		 */
		since(start) {
			if (!start) {
				return ''
			}
			return Number(start.slice(0, 4)) === this.year ? formatDate(start) : start.slice(0, 4)
		},

		/**
		 * Red when overdrawn, amber when nearly spent.
		 *
		 * @param {object|null} annual the annual balance row
		 * @return {string}
		 */
		leftClass(annual) {
			if (!annual || !(annual.entitlement > 0)) {
				return ''
			}
			if (annual.remaining < 0) {
				return 'neg'
			}
			return annual.remaining < annual.entitlement * LOW_BALANCE_RATIO ? 'low' : ''
		},

		async reload() {
			this.loading = true
			try {
				const list = await api.listEmployees(this.year)
				this.rows = list.rows
				this.employeesGroup = list.employeesGroup
				this.contacts = list.contacts
			} catch (e) {
				showError(e.response?.data?.message || t('absence', 'Could not load the employees'))
			} finally {
				this.loading = false
			}
		},

		async onSaved() {
			this.editing = null
			this.editingJson = false
			await this.reload()
		},
	},
}
</script>

<style scoped lang="scss">
.employees {
	&__sub {
		margin: 4px 0 0;
		color: var(--color-text-maxcontrast);
	}

	&__link {
		color: var(--color-primary-element);
		font-weight: 600;
	}

	&__filters {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 12px;
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	// Eleven columns: tighter than the shared table so they fit the page
	&__table.tbl {
		th, td {
			padding-inline: 8px;
		}
	}

	&__name {
		display: inline-flex;
		align-items: center;
		gap: 10px;
	}

	&__muted,
	&__note {
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
	}

	&__note {
		margin: 0;
	}

	&__more {
		align-self: center;
	}
}

// Amber, not red: the days left are nearly spent, which is worth noticing but is
// not yet the error that an overdrawn balance is. Qualified with the element so it
// outranks the global `.tbl .lead` colour on the same cell.
td.low {
	color: var(--color-warning);
}
</style>
