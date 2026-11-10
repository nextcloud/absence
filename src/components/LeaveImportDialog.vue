<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - HR's import of past leave (§6.5): paste or pick a JSON list, review what it
  - adds per person, then import it as approved leave.
-->
<template>
	<NcModal :name="t('absence', 'Import leave')" size="large" @close="$emit('close')">
		<div class="leave-import">
			<h3>{{ t('absence', 'Import leave') }}</h3>
			<p>{{ t('absence', 'Paste a JSON list or pick a file. The leave is booked as approved, without notifying anyone. Leave already in the app is skipped, and nothing is imported while an entry is invalid.') }}</p>
			<div>
				<input
					ref="fileInput"
					type="file"
					accept=".json,application/json"
					class="leave-import__file"
					@change="onFilePicked">
				<NcButton variant="secondary" @click="$refs.fileInput.click()">
					<template #icon>
						<FileUpload :size="20" />
					</template>
					{{ t('absence', 'Choose a file') }}
				</NcButton>
			</div>
			<NcTextArea
				v-model="json"
				class="leave-import__json"
				:label="t('absence', 'Leave as JSON')"
				:placeholder="example"
				resize="vertical"
				rows="10"
				@update:modelValue="preview = null" />
			<p v-if="error" class="leave-import__error">
				{{ error }}
			</p>

			<template v-if="preview">
				<NcNoteCard v-if="preview.errors.length" type="error">
					<p>{{ n('absence', 'Nothing was imported. Fix this entry and review again.', 'Nothing was imported. Fix these {count} entries and review again.', preview.errors.length, { count: preview.errors.length }) }}</p>
					<ul class="leave-import__errors">
						<li v-for="e in preview.errors" :key="e.entry">
							{{ t('absence', 'Entry {entry}', { entry: e.entry }) }}<template v-if="e.user">
								· {{ e.user }}
							</template>: {{ e.message }}
						</li>
					</ul>
				</NcNoteCard>
				<template v-else>
					<p>
						<strong>{{ n('absence', '{count} entry to import', '{count} entries to import', preview.entries, { count: preview.entries }) }}</strong>
						{{ n('absence', '· {count} day', '· {count} days', preview.days, { count: preview.days }) }}
						{{ n('absence', '· {count} already there', '· {count} already there', preview.skipped, { count: preview.skipped }) }}
					</p>
					<table class="leave-import__people">
						<thead>
							<tr>
								<th>{{ t('absence', 'Name') }}</th>
								<th>{{ t('absence', 'Entries') }}</th>
								<th>{{ t('absence', 'Days') }}</th>
								<th>{{ t('absence', 'Skipped') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="person in preview.people" :key="person.uid">
								<td>{{ person.name }}</td>
								<td>{{ person.entries }}</td>
								<td>{{ person.days }}</td>
								<td>{{ person.skipped }}</td>
							</tr>
						</tbody>
					</table>
				</template>
			</template>

			<div class="leave-import__buttons">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('absence', 'Cancel') }}
				</NcButton>
				<NcButton
					v-if="!preview || preview.errors.length"
					variant="primary"
					:disabled="busy || !json.trim()"
					@click="review">
					{{ t('absence', 'Review') }}
				</NcButton>
				<NcButton
					v-else
					variant="primary"
					:disabled="busy || !preview.entries"
					@click="runImport">
					{{ n('absence', 'Import {count} entry', 'Import {count} entries', preview.entries, { count: preview.entries }) }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import FileUpload from 'vue-material-design-icons/FileUploadOutline.vue'
import api from '../api.js'

export default {
	name: 'LeaveImportDialog',
	components: { NcButton, NcModal, NcNoteCard, NcTextArea, FileUpload },
	emits: ['close', 'imported'],

	data() {
		return {
			json: '',
			preview: null,
			error: '',
			busy: false,
			example: JSON.stringify([
				{ user: 'robin', type: 'annual', from: '2025-03-03', to: '2025-03-07', workingDays: 5, note: 'Ski trip' },
			], null, 2),
		}
	},

	methods: {
		t,
		n,

		async onFilePicked(event) {
			const file = event.target.files?.[0]
			event.target.value = ''
			if (!file) {
				return
			}
			this.json = await file.text()
			this.preview = null
			this.error = ''
		},

		async review() {
			this.busy = true
			this.error = ''
			try {
				this.preview = await api.importLeave(this.json, true)
			} catch (e) {
				this.preview = null
				this.error = e.response?.data?.message || t('absence', 'Could not read the list')
			} finally {
				this.busy = false
			}
		},

		async runImport() {
			this.busy = true
			this.error = ''
			try {
				const result = await api.importLeave(this.json, false)
				showSuccess(n('absence', '{count} entry imported', '{count} entries imported', result.entries, { count: result.entries }))
				this.$emit('imported')
			} catch (e) {
				this.preview = null
				this.error = e.response?.data?.message || t('absence', 'Could not import the list')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.leave-import {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	padding: calc(4 * var(--default-grid-baseline));

	&__file {
		display: none;
	}

	&__json :deep(textarea) {
		font-family: var(--font-face-monospace, monospace);
	}

	&__error {
		white-space: pre-line;
		color: var(--color-error-text);
	}

	&__errors {
		margin: 0;
		padding-inline-start: calc(4 * var(--default-grid-baseline));
		list-style: disc;
	}

	&__people {
		width: 100%;
		border-collapse: collapse;

		th,
		td {
			padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
			border-bottom: 1px solid var(--color-border);
			text-align: start;
		}

		th:not(:first-child),
		td:not(:first-child) {
			text-align: end;
		}
	}

	&__buttons {
		display: flex;
		justify-content: flex-end;
		gap: var(--default-grid-baseline);
	}
}
</style>
