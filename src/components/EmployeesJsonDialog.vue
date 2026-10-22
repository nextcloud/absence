<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - HR edits everybody's region code, dates and contract as one JSON list (§6.5):
  - edit or paste, review what changes, apply all of it or nothing.
-->
<template>
	<NcModal :name="t('absence', 'Edit employees as JSON')" size="large" @close="$emit('close')">
		<div class="json">
			<template v-if="!plan">
				<header class="json__header">
					<h3>{{ t('absence', 'Edit employees as JSON') }}</h3>
					<NcButton variant="tertiary" :disabled="loading" @click="download">
						<template #icon>
							<Download :size="20" />
						</template>
						{{ t('absence', 'Download') }}
					</NcButton>
				</header>
				<NcNoteCard v-if="errors.length" type="error">
					<p>
						<strong>{{ t('absence', 'Nothing was saved.') }}</strong>
						{{ n('absence', 'Fix this entry and review again.', 'Fix these {count} entries and review again.', errors.length, { count: errors.length }) }}
					</p>
					<ul class="json__errors">
						<li v-for="(error, index) in errors" :key="index">
							{{ where(error) }}{{ error.message }}
						</li>
					</ul>
				</NcNoteCard>
				<p v-else class="json__hint">
					{{ n('absence', 'The current data of the {count} employee. Edit it or paste a whole list, then review. "name" is ignored, it is there to read the list.', 'The current data of the {count} employees. Edit it or paste a whole list, then review. "name" is ignored, it is there to read the list.', count, { count }) }}
				</p>
				<SkeletonList v-if="loading" :rows="6" />
				<div v-else class="json__editor">
					<ol ref="gutter" class="json__gutter" aria-hidden="true">
						<li v-for="line in lineCount" :key="line" :class="{ 'json__gutter--error': errorLines.has(line) }">
							{{ line }}
						</li>
					</ol>
					<textarea
						v-model="json"
						class="json__text"
						spellcheck="false"
						:aria-label="t('absence', 'Employees as JSON')"
						@scroll="syncGutter" />
				</div>
				<div class="json__buttons">
					<NcButton @click="$emit('close')">
						{{ t('absence', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary" :disabled="loading || busy || !json.trim()" @click="review">
						{{ t('absence', 'Review changes') }}
					</NcButton>
				</div>
			</template>

			<template v-else>
				<h3>
					{{ plan.changes.length
						? n('absence', 'Review {count} change', 'Review {count} changes', plan.changes.length, { count: plan.changes.length })
						: t('absence', 'Nothing changes') }}
				</h3>
				<p class="json__hint">
					{{ n('absence', '{count} employee stays the same.', '{count} employees stay the same.', plan.unchanged, { count: plan.unchanged }) }}
				</p>
				<div v-if="plan.changes.length" class="json__table">
					<table>
						<thead>
							<tr>
								<th>{{ t('absence', 'Employee') }}</th>
								<th>{{ t('absence', 'Field') }}</th>
								<th>{{ t('absence', 'Now') }}</th>
								<th>{{ t('absence', 'After') }}</th>
							</tr>
						</thead>
						<tbody>
							<template v-for="change in plan.changes" :key="change.user">
								<tr v-for="(field, index) in change.fields" :key="change.user + field.field">
									<td>{{ index === 0 ? change.name : '' }}</td>
									<td>{{ fieldLabel(field.field) }}</td>
									<td class="json__now">
										<span v-for="(line, i) in describe(field).now" :key="i" class="json__line">{{ line }}</span>
									</td>
									<td class="json__after">
										<span v-for="(line, i) in describe(field).after" :key="i" class="json__line">{{ line }}</span>
									</td>
								</tr>
							</template>
						</tbody>
					</table>
				</div>
				<div class="json__buttons">
					<NcButton @click="plan = null">
						{{ t('absence', 'Back to editing') }}
					</NcButton>
					<NcButton variant="primary" :disabled="busy || !plan.changes.length" @click="apply">
						{{ n('absence', 'Apply {count} change', 'Apply {count} changes', plan.changes.length, { count: plan.changes.length }) }}
					</NcButton>
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Download from 'vue-material-design-icons/Download.vue'
import SkeletonList from './SkeletonList.vue'
import api from '../api.js'
import { contractDiff, describePeriod } from '../utils/contract.js'
import { formatDate } from '../utils/dates.js'
import { regionFlag } from '../utils/regions.js'

export default {
	name: 'EmployeesJsonDialog',
	components: { Download, NcButton, NcModal, NcNoteCard, SkeletonList },
	emits: ['close', 'saved'],

	data() {
		return {
			loading: true,
			busy: false,
			json: '',
			count: 0,
			plan: null,
			errors: [],
		}
	},

	computed: {
		lineCount() {
			return this.json.split('\n').length
		},

		errorLines() {
			return new Set(this.errors.map((error) => error.line).filter((line) => line !== null))
		},
	},

	async mounted() {
		try {
			const exported = await api.exportEmployeesJson()
			this.json = exported.json
			this.count = exported.count
		} catch (e) {
			showError(e.response?.data?.message || t('absence', 'Could not load the employees'))
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
		n,

		where(error) {
			if (error.line !== null) {
				return t('absence', 'Line {line}: ', { line: error.line })
			}
			return error.entry !== null ? t('absence', 'Entry {entry}: ', { entry: error.entry }) : ''
		},

		fieldLabel(field) {
			return {
				region: t('absence', 'Region'),
				start: t('absence', 'Start'),
				end: t('absence', 'End'),
				contract: t('absence', 'Contract'),
			}[field] ?? field
		},

		/**
		 * The two sides of a changed field, as lines of text.
		 *
		 * @param {{field: string, before: (string|object[]|null), after: (string|object[]|null)}} change a changed field
		 * @return {{now: string[], after: string[]}}
		 */
		describe(change) {
			const none = t('absence', 'none')
			if (change.field === 'region') {
				const code = (value) => value ? `${regionFlag(value)} ${value}` : none
				return { now: [code(change.before)], after: [code(change.after)] }
			}
			if (change.field !== 'contract') {
				return { now: [change.before ? formatDate(change.before) : none], after: [change.after ? formatDate(change.after) : none] }
			}
			const { removed, added, kept } = contractDiff(change.before, change.after)
			const from = (period) => t('absence', '{pattern} from {date}', { pattern: describePeriod(period), date: formatDate(period.from) })
			const since = (period) => t('absence', '{pattern} since {date}', { pattern: describePeriod(period), date: formatDate(period.from) })
			return {
				now: removed.length ? removed.map(from) : (kept.length ? kept.map(since) : [none]),
				after: added.length ? added.map(from) : [t('absence', 'removed')],
			}
		},

		syncGutter(event) {
			this.$refs.gutter.scrollTop = event.target.scrollTop
		},

		download() {
			const link = document.createElement('a')
			link.href = URL.createObjectURL(new Blob([this.json], { type: 'application/json' }))
			link.download = 'employees.json'
			link.click()
			URL.revokeObjectURL(link.href)
		},

		async review() {
			this.busy = true
			try {
				const plan = await api.importEmployeesJson(this.json, true)
				this.errors = plan.errors
				this.plan = plan.errors.length ? null : plan
			} catch (e) {
				showError(e.response?.data?.message || t('absence', 'Could not check the list'))
			} finally {
				this.busy = false
			}
		},

		async apply() {
			this.busy = true
			try {
				const result = await api.importEmployeesJson(this.json, false)
				showSuccess(n('absence', '{count} employee updated', '{count} employees updated', result.changes.length, { count: result.changes.length }))
				this.$emit('saved')
			} catch (e) {
				showError(e.response?.data?.message || t('absence', 'Could not save the list'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.json {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: calc(var(--default-grid-baseline, 4px) * 5);

	h3 {
		margin: 0;
	}

	// Clear of the modal's close button
	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding-inline-end: 36px;
	}

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__errors {
		margin: 4px 0 0;
		padding: 0;
		list-style: none;
	}

	// The gutter and the text share font and line height, so the numbers stay
	// beside their lines.
	&__editor {
		--json-line: 1.5em;
		display: flex;
		height: 50vh;
		min-height: 240px;
		border: 2px solid var(--color-border-maxcontrast);
		border-radius: var(--border-radius-large);
		background: var(--color-background-hover);
		font-family: var(--font-face-monospace, monospace);
		font-size: 0.85rem;
		overflow: hidden;
	}

	&__gutter {
		flex: 0 0 auto;
		margin: 0;
		padding: 8px 8px 8px 12px;
		list-style: none;
		overflow: hidden;
		text-align: end;
		color: var(--color-text-maxcontrast);
		user-select: none;

		li {
			height: var(--json-line);
			line-height: var(--json-line);
		}

		&--error {
			color: var(--color-error-text);
			font-weight: bold;
		}
	}

	&__text {
		flex: 1 1 auto;
		height: 100%;
		margin: 0;
		padding: 8px;
		border: none;
		border-radius: 0;
		background: transparent;
		color: var(--color-main-text);
		font: inherit;
		line-height: var(--json-line);
		white-space: pre;
		overflow: auto;
		resize: none;
		tab-size: 4;

		&:focus,
		&:focus-visible {
			box-shadow: none;
			outline: none;
		}
	}

	&__editor:focus-within {
		border-color: var(--color-main-text);
	}

	// The modal sits outside the app's root, where the shared table styles apply
	&__table {
		max-height: 50vh;
		overflow: auto;

		table {
			width: 100%;
			border-collapse: collapse;
		}

		th, td {
			padding: 10px 8px;
			text-align: start;
			vertical-align: top;
			border-bottom: 1px solid var(--color-border);
		}

		th {
			font-size: 0.76rem;
			font-weight: 600;
			color: var(--color-text-maxcontrast);
			text-transform: uppercase;
			letter-spacing: 0.05em;
		}
	}

	&__line {
		display: block;
	}

	&__now {
		color: var(--color-text-maxcontrast);
	}

	&__after {
		color: var(--color-success-text);
		font-weight: 600;
	}

	&__buttons {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
	}
}
</style>
