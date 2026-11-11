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
				<NcButton variant="secondary" @click="fileInput?.click()">
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
								<th>{{ t('absence', 'Account') }}</th>
								<th>{{ t('absence', 'Entries') }}</th>
								<th>{{ t('absence', 'Days') }}</th>
								<th>{{ t('absence', 'Skipped') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="person in preview.people" :key="person.uid">
								<td>{{ person.name }}</td>
								<td>{{ person.uid }}</td>
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

<script setup lang="ts">
import type { LeaveImportResult } from '../types/index.ts'

import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import FileUpload from 'vue-material-design-icons/FileUploadOutline.vue'
import api from '../services/api.ts'
import { serverMessage } from '../utils/errors.ts'

const emit = defineEmits<{
	close: []
	imported: []
}>()

const json = ref('')
const preview = ref<LeaveImportResult | null>(null)
const error = ref('')
const busy = ref(false)
const example = JSON.stringify([
	{ user: 'robin', type: 'annual', from: '2025-03-03', to: '2025-03-07', workingDays: 5, note: 'Ski trip' },
], null, 2)

const fileInput = useTemplateRef<HTMLInputElement>('fileInput')

/**
 * Puts the text of the picked file in the field, to review like a pasted list.
 *
 * @param event the change of the file input
 */
async function onFilePicked(event: Event) {
	const input = event.target
	if (!(input instanceof HTMLInputElement)) {
		return
	}
	const file = input.files?.[0]
	input.value = ''
	if (!file) {
		return
	}
	json.value = await file.text()
	preview.value = null
	error.value = ''
}

/**
 * Asks the server what the list would add, or what is wrong with it.
 */
async function review() {
	busy.value = true
	error.value = ''
	try {
		preview.value = await api.importLeave(json.value, true)
	} catch (e) {
		preview.value = null
		error.value = serverMessage(e) || t('absence', 'Could not read the list')
	} finally {
		busy.value = false
	}
}

/**
 * Books the reviewed list as approved leave.
 */
async function runImport() {
	busy.value = true
	error.value = ''
	try {
		const result = await api.importLeave(json.value, false)
		showSuccess(n('absence', '{count} entry imported', '{count} entries imported', result.entries, { count: result.entries }))
		emit('imported')
	} catch (e) {
		preview.value = null
		error.value = serverMessage(e) || t('absence', 'Could not import the list')
	} finally {
		busy.value = false
	}
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

		// The counts, after the name and the account
		th:nth-child(n + 3),
		td:nth-child(n + 3) {
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
