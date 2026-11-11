<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppSidebar
		v-if="detail"
		:name="type.label"
		:subname="subLabel"
		@close="$emit('close')">
		<template v-if="showStatus" #description>
			<StatusChip :status="detail.status" />
		</template>

		<NcAppSidebarTab id="details" :name="t('absence', 'Details')" :order="1">
			<template #icon>
				<InformationOutline :size="20" />
			</template>
			<div class="section">
				<!-- Short notice bears on whether to approve at all, so it leads the tab
				     the decide buttons are on rather than sitting behind the Coverage one. -->
				<NcNoteCard v-if="shortNoticeLabel" type="warning">
					{{ shortNoticeLabel }}
				</NcNoteCard>
				<RequestStepper v-if="showStatus" :status="detail.status" class="section__stepper" />
				<dl class="facts">
					<dt>{{ t('absence', 'Employee') }}</dt>
					<dd class="facts__decided">
						<NcAvatar
							:user="detail.employeeUid"
							:displayName="employeeName"
							:size="20"
							hideStatus />
						{{ employeeName }}
					</dd>
					<dt>{{ t('absence', 'Type') }}</dt>
					<dd><LeaveTypeChip :typeId="detail.typeId" /></dd>
					<dt>{{ t('absence', 'Dates') }}</dt>
					<dd>{{ rangeLabel }}</dd>
					<dt>{{ t('absence', 'Working days') }}</dt>
					<dd>{{ detail.workingDays }}</dd>
					<!-- What this absence leaves them with, so nobody has to go to the
					     Balances report to find out. Absent for leave that counts against
					     nothing, where "left" has no answer. -->
					<template v-if="detail.disability">
						<dt>{{ t('absence', 'Disability') }}</dt>
						<dd>
							<span aria-hidden="true">♿</span>
							{{ t('absence', 'Disability-related — visible to HR only') }}
						</dd>
					</template>
					<template v-if="detail.balance">
						<dt>{{ t('absence', 'Balance {year}', { year: detail.balance.year }) }}</dt>
						<dd class="facts__balance">
							<strong>{{ t('absence', '{days} left', { days: fmtDays(detail.balance.remaining) }) }}</strong>
							<span class="facts__muted">
								{{ t('absence', 'of {total} · {used} taken', {
									total: fmtDays(detail.balance.entitlement),
									used: fmtDays(detail.balance.used),
								}) }}
							</span>
							<span v-if="detail.balance.pending > 0" class="facts__muted">
								{{ t('absence', '{days} awaiting a decision, leaving {available} free to book', {
									days: fmtDays(detail.balance.pending),
									available: fmtDays(detail.balance.available),
								}) }}
							</span>
						</dd>
					</template>
					<template v-if="detail.replacementUid">
						<dt>{{ t('absence', 'Replacement') }}</dt>
						<dd class="facts__decided">
							<NcAvatar :user="detail.replacementUid" :size="20" hideStatus />
							{{ detail.replacementName || detail.replacementUid }}
						</dd>
					</template>
					<template v-if="detail.reason">
						<dt>{{ t('absence', 'Reason') }}</dt>
						<dd>{{ detail.reason }}</dd>
					</template>
					<template v-if="detail.decidedBy">
						<dt>{{ t('absence', 'Decided by') }}</dt>
						<dd class="facts__decided">
							<NcAvatar
								:user="detail.decidedBy"
								:displayName="detail.decidedByName || detail.decidedBy"
								:size="20"
								hideStatus />
							{{ detail.decidedByName || detail.decidedBy }}<span v-if="decidedAtLabel" class="facts__muted"> · {{ decidedAtLabel }}</span>
						</dd>
					</template>
					<template v-if="detail.decisionComment">
						<dt>{{ t('absence', 'Decision note') }}</dt>
						<dd>{{ detail.decisionComment }}</dd>
					</template>
				</dl>

				<div v-if="detail.canAttach || (detail.attachments && detail.attachments.length)" class="attachments">
					<h4 class="attachments__title">
						{{ t('absence', 'Attachments') }}
						<span class="attachments__hint">{{ t('absence', 'visible to HR and the employee') }}</span>
					</h4>
					<ul v-if="detail.attachments && detail.attachments.length" class="attachments__list">
						<li v-for="a in detail.attachments" :key="a.id" class="attachments__row">
							<a
								:href="attachmentUrl(a.id)"
								class="attachments__link"
								target="_blank"
								rel="noreferrer noopener">
								📎 {{ a.name }}
							</a>
							<span class="attachments__meta">{{ formatSize(a.size) }}</span>
							<NcButton
								v-if="canRemoveAttachment(a)"
								variant="tertiary"
								:ariaLabel="t('absence', 'Remove attachment')"
								@click="removing = a">
								<template #icon>
									<Close :size="16" />
								</template>
							</NcButton>
						</li>
					</ul>
					<template v-if="detail.canAttach">
						<input
							ref="attachmentInput"
							type="file"
							class="attachments__input"
							@change="onAttachmentPicked">
						<NcButton variant="tertiary" :disabled="uploading" @click="pickAttachment">
							<template #icon>
								<Paperclip :size="18" />
							</template>
							{{ uploading ? t('absence', 'Uploading…') : t('absence', 'Attach a file') }}
						</NcButton>
					</template>
				</div>

				<div class="actions">
					<template v-if="detail.canDecide && isDecidable">
						<NcButton variant="success" :disabled="busy" @click="approve">
							<template #icon>
								<Check :size="20" />
							</template>
							{{ decideLabelApprove }}
						</NcButton>
						<NcButton variant="error" :disabled="busy" @click="startReject">
							<template #icon>
								<Close :size="20" />
							</template>
							{{ decideLabelReject }}
						</NcButton>
					</template>
					<template v-if="detail.canModify && isModifiable">
						<NcButton
							v-if="canEdit"
							variant="secondary"
							:disabled="busy"
							@click="$emit('edit', detail)">
							<template #icon>
								<Pencil :size="20" />
							</template>
							{{ t('absence', 'Edit') }}
						</NcButton>
						<NcButton variant="tertiary" :disabled="busy" @click="startCancel">
							<template #icon>
								<CancelIcon :size="20" />
							</template>
							{{ cancelLabel }}
						</NcButton>
					</template>
				</div>

				<!-- HR's cancel is immediate and notifies the employee, and it is reached
				     from a browse list where a misclick is easy — so it is confirmed. -->
				<NcNoteCard v-if="confirmingCancel" type="warning">
					<p>{{ t('absence', 'Cancel this absence for {employee}? They are notified, and it is removed from the calendars. The record itself is kept, marked cancelled.', { employee: employeeName }, undefined, { escape: false }) }}</p>
					<div class="confirm__actions">
						<NcButton variant="tertiary" :disabled="busy" @click="confirmingCancel = false">
							{{ t('absence', 'Back') }}
						</NcButton>
						<NcButton variant="error" :disabled="busy" @click="cancel">
							{{ t('absence', 'Confirm cancellation') }}
						</NcButton>
					</div>
				</NcNoteCard>

				<div v-if="rejecting" class="reject">
					<NcTextArea
						v-model="rejectComment"
						:label="t('absence', 'Reason for declining')"
						rows="2" />
					<div class="reject__actions">
						<NcButton variant="tertiary" @click="rejecting = false">
							{{ t('absence', 'Back') }}
						</NcButton>
						<NcButton variant="error" :disabled="rejectComment.trim() === '' || busy" @click="reject">
							{{ t('absence', 'Confirm decline') }}
						</NcButton>
					</div>
				</div>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab
			v-if="detail.coverage"
			id="coverage"
			:name="t('absence', 'Coverage')"
			:order="2">
			<template #icon>
				<AccountGroup :size="20" />
			</template>
			<CoveragePanel :coverage="detail.coverage" />
		</NcAppSidebarTab>

		<NcAppSidebarTab id="comments" :name="t('absence', 'Comments')" :order="3">
			<template #icon>
				<CommentOutline :size="20" />
			</template>
			<div class="section">
				<ul v-if="detail.comments.length" class="comments">
					<li v-for="c in detail.comments" :key="c.id" class="comments__item">
						<div class="comments__head">
							<NcAvatar
								:user="c.authorUid"
								:displayName="c.authorName || c.authorUid"
								:size="24"
								hideStatus />
							<strong>{{ c.authorName || c.authorUid }}</strong>
						</div>
						<p>{{ c.body }}</p>
					</li>
				</ul>
				<NcEmptyContent v-else :name="t('absence', 'No comments yet')" :description="withheld ? undefined : t('absence', 'Start the conversation below.')">
					<template #icon>
						<CommentOutline :size="20" />
					</template>
				</NcEmptyContent>
				<div v-if="!withheld" class="comment-add">
					<NcTextArea v-model="newComment" :placeholder="t('absence', 'Add a comment…')" rows="2" />
					<NcButton variant="secondary" :disabled="newComment.trim() === '' || busy" @click="postComment">
						{{ t('absence', 'Send') }}
					</NcButton>
				</div>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="history" :name="t('absence', 'History')" :order="4">
			<template #icon>
				<History :size="20" />
			</template>
			<div class="section">
				<ol v-if="detail.history && detail.history.length" class="timeline">
					<li v-for="ev in detail.history" :key="ev.id" class="timeline__item">
						<span class="timeline__marker" aria-hidden="true">{{ eventMeta(ev.eventType).icon }}</span>
						<div class="timeline__body">
							<div class="timeline__head">
								<strong>{{ eventMeta(ev.eventType).label }}</strong>
								<span class="timeline__time">{{ formatDateTime(ev.createdAt) }}</span>
							</div>
							<div class="timeline__who">
								<template v-if="ev.actorUid === 'system'">
									{{ t('absence', 'Automatically') }}
								</template>
								<template v-else-if="ev.actorUid">
									<NcAvatar
										:user="ev.actorUid"
										:displayName="ev.actorName || ev.actorUid"
										:size="20"
										hideStatus />
									{{ ev.actorName || ev.actorUid }}
								</template>
								<template v-else>
									{{ t('absence', 'An administrator') }}
								</template>
							</div>
							<p v-if="ev.detail" class="timeline__detail">
								{{ ev.detail }}
							</p>
						</div>
					</li>
				</ol>
				<NcEmptyContent v-else :name="t('absence', 'No history yet')">
					<template #icon>
						<History :size="20" />
					</template>
				</NcEmptyContent>
			</div>
		</NcAppSidebarTab>

		<NcDialog
			v-if="removing"
			:name="t('absence', 'Remove {name}?', { name: removing.name }, undefined, { escape: false })"
			:open="true"
			size="small"
			@update:open="(open) => !open && !removingBusy && (removing = null)">
			<p>{{ t('absence', 'The file is deleted and cannot be restored.') }}</p>
			<template #actions>
				<NcButton :disabled="removingBusy" @click="removing = null">
					{{ t('absence', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" :disabled="removingBusy" @click="removeAttachment">
					{{ t('absence', 'Remove') }}
				</NcButton>
			</template>
		</NcDialog>
	</NcAppSidebar>
	<NcAppSidebar
		v-else
		:name="t('absence', 'Loading…')"
		loading
		@close="$emit('close')" />
</template>

<script setup lang="ts">
import type { Attachment, LeaveRequestDetail, RequestEventType } from '../types/index.ts'

import { isAxiosError } from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, onMounted, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CommentOutline from 'vue-material-design-icons/CommentOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import CoveragePanel from './CoveragePanel.vue'
import LeaveTypeChip from './LeaveTypeChip.vue'
import RequestStepper from './RequestStepper.vue'
import StatusChip from './StatusChip.vue'
import api from '../services/api.ts'
import { useLeaveTypesStore } from '../stores/leaveTypes.ts'
import { useRequestsStore } from '../stores/requests.ts'
import { useSessionStore } from '../stores/session.ts'
import { formatRange } from '../utils/dates.ts'
import { serverMessage } from '../utils/errors.ts'
import { locale } from '../utils/locale.ts'

const emit = defineEmits<{
	close: []
	edit: [request: LeaveRequestDetail]
	changed: []
}>()
const leaveTypesStore = useLeaveTypesStore()
const sessionStore = useSessionStore()
const requestsStore = useRequestsStore()

/** How a history entry shows */
interface EventMeta {
	label: string
	icon: string
}

const route = useRoute()
const router = useRouter()

const attachmentInputElement = useTemplateRef<HTMLInputElement>('attachmentInput')

const detail = ref<LeaveRequestDetail | null>(null)
const busy = ref(false)
const rejecting = ref(false)
const confirmingCancel = ref(false)
const rejectComment = ref('')
const newComment = ref('')
const uploading = ref(false)
// The attachment whose removal is being confirmed
const removing = ref<Attachment | null>(null)
const removingBusy = ref(false)

const type = computed(() => leaveTypesStore.leaveType(detail.value?.typeId))

const rangeLabel = computed(() => detail.value ? formatRange(detail.value.startDate, detail.value.endDate) : '')

/** Older payloads carry no name; the uid is a poor label but better than blank. */
const employeeName = computed(() => detail.value?.employeeName || detail.value?.employeeUid || '')

/**
 * Whose absence this is, shown under the title — but only for other people's
 * leave. On your own there is nobody to disambiguate, and naming you back at
 * yourself just crowds out the dates.
 */
const subLabel = computed(() => {
	if (!detail.value || detail.value.employeeUid === sessionStore.session.uid) {
		return rangeLabel.value
	}
	return `${employeeName.value} · ${rangeLabel.value}`
})

const decidedAtLabel = computed(() => detail.value && detail.value.decidedAt ? formatDateTime(detail.value.decidedAt) : '')

/**
 * The short-notice warning, phrased to match the one in the notification and
 * the email (NoticeService::sentence) so the same request never reads two
 * different ways. Absent unless the server sent one, which it only does for
 * someone who may decide and while a decision is still outstanding.
 */
const shortNoticeLabel = computed(() => {
	const notice = detail.value?.shortNotice
	if (!notice) {
		return ''
	}
	if (notice.days > 0) {
		return n('absence', 'The leave starts in %n day, less than the {period} days of notice expected.', 'The leave starts in %n days, less than the {period} days of notice expected.', notice.days, { period: notice.noticePeriod })
	}
	if (notice.days === 0) {
		return t('absence', 'The leave starts today, with none of the {period} days of notice expected.', { period: notice.noticePeriod })
	}
	return t('absence', 'The leave has already started, though {period} days of notice are expected.', { period: notice.noticePeriod })
})

/** A confidential absence seen by someone outside HR: no category, no thread. */
const withheld = computed(() => detail.value?.typeId === null)

const showStatus = computed(() => detail.value ? leaveTypesStore.statusVisible(detail.value) : true)

const isDecidable = computed(() => detail.value !== null && ['PENDING', 'ESCALATED', 'WITHDRAWAL_PENDING'].includes(detail.value.status))

const isModifiable = computed(() => detail.value !== null && !['REJECTED', 'CANCELLED'].includes(detail.value.status))

const canEdit = computed(() => detail.value !== null && ['PENDING', 'ESCALATED', 'APPROVED'].includes(detail.value.status))

const isWithdrawal = computed(() => detail.value?.status === 'WITHDRAWAL_PENDING')

const decideLabelApprove = computed(() => isWithdrawal.value ? t('absence', 'Approve withdrawal') : t('absence', 'Approve'))

const decideLabelReject = computed(() => isWithdrawal.value ? t('absence', 'Keep leave') : t('absence', 'Decline'))

/**
 * HR cancelling somebody else's leave — or their own HR-recorded leave —
 * takes effect immediately, with no withdrawal-approval step (§5.6). Mirrors
 * the same condition in `RequestService::cancel`.
 */
const hrOverride = computed(() => !!sessionStore.session.isHr
	&& detail.value !== null
	&& (detail.value.employeeUid !== sessionStore.session.uid || leaveTypesStore.isHrRecorded(detail.value)))

const cancelLabel = computed(() => {
	if (detail.value?.status === 'APPROVED' && !hrOverride.value) {
		return t('absence', 'Request withdrawal')
	}
	return hrOverride.value ? t('absence', 'Cancel absence') : t('absence', 'Cancel request')
})

onMounted(() => {
	// App.vue remounts this component (via :key="requestsStore.selectedId") whenever the
	// selection changes, so loading once on mount always reflects the current id.
	load()
})

/**
 * Where an attachment is downloaded from.
 *
 * @param id the attachment's id
 */
function attachmentUrl(id: number): string {
	return api.attachmentUrl(id)
}

/**
 * Whether the viewer may remove an attachment: HR, or whoever uploaded it.
 *
 * @param a the attachment
 */
function canRemoveAttachment(a: Attachment): boolean {
	return sessionStore.session.isHr || a.uploaderUid === sessionStore.session.uid
}

/**
 * A file size in B, KB or MB.
 *
 * @param bytes the size in bytes
 */
function formatSize(bytes: number): string {
	if (bytes < 1024) {
		return bytes + ' B'
	}
	if (bytes < 1024 * 1024) {
		return Math.round(bytes / 1024) + ' KB'
	}
	return (bytes / 1024 / 1024).toFixed(1) + ' MB'
}

/**
 * Opens the file picker of the hidden input.
 */
function pickAttachment() {
	attachmentInputElement.value?.click()
}

/**
 * Uploads the picked file and reloads the request.
 *
 * @param event the change event of the file input
 */
async function onAttachmentPicked(event: Event) {
	const input = event.target
	if (!(input instanceof HTMLInputElement)) {
		return
	}
	const file = input.files?.[0]
	input.value = ''
	if (!file || !detail.value) {
		return
	}
	const limit = sessionStore.session.maxAttachmentBytes
	if (limit && file.size > limit) {
		showError(t('absence', 'Attachments can be up to {size} MB.', { size: limit / 1024 / 1024 }))
		return
	}
	uploading.value = true
	try {
		await api.uploadAttachment(detail.value.id, file)
		await load()
	} catch (e) {
		showError(serverMessage(e) || t('absence', 'Could not upload the file'))
	} finally {
		uploading.value = false
	}
}

/**
 * Deletes the attachment whose removal was confirmed and reloads the request.
 */
async function removeAttachment() {
	if (!removing.value) {
		return
	}
	removingBusy.value = true
	try {
		await api.deleteAttachment(removing.value.id)
		removing.value = null
		await load()
	} catch (e) {
		showError(serverMessage(e) || t('absence', 'Could not remove the attachment'))
	} finally {
		removingBusy.value = false
	}
}

/**
 * The label and icon of a history entry.
 *
 * @param type the event type, as the server names it
 */
function eventMeta(type: RequestEventType): EventMeta {
	const map: Record<string, EventMeta> = {
		request_created: { label: t('absence', 'Requested'), icon: '📝' },
		request_imported: { label: t('absence', 'Imported'), icon: '📥' },
		request_updated: { label: t('absence', 'Edited'), icon: '✏️' },
		request_edited_superseding: { label: t('absence', 'Edited (needs re-approval)'), icon: '✏️' },
		request_hr_edited: { label: t('absence', 'Adjusted by HR'), icon: '🛠️' },
		disability_changed: { label: t('absence', 'Disability flag changed'), icon: '♿' },
		withdrawal_requested: { label: t('absence', 'Withdrawal requested'), icon: '↩️' },
		request_cancelled: { label: t('absence', 'Cancelled'), icon: '🚫' },
		withdrawal_approved: { label: t('absence', 'Withdrawal approved'), icon: '↩️' },
		request_approved: { label: t('absence', 'Approved'), icon: '✅' },
		request_rejected: { label: t('absence', 'Declined'), icon: '✋' },
		withdrawal_rejected: { label: t('absence', 'Withdrawal declined'), icon: '✋' },
		request_escalated: { label: t('absence', 'Escalated to HR'), icon: '⏫' },
		comment_added: { label: t('absence', 'Comment'), icon: '💬' },
	} satisfies Record<RequestEventType, EventMeta>
	return map[type] || { label: type, icon: '•' }
}

/**
 * A timestamp as a short date and time in the user's locale.
 *
 * @param iso the ISO 8601 timestamp, empty when there is none
 */
function formatDateTime(iso: string | null): string {
	if (!iso) {
		return ''
	}
	return new Date(iso).toLocaleString(locale(), {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	})
}

/**
 * Loads the selected request and resets the decline and cancel forms.
 */
async function load() {
	if (!requestsStore.selectedId) {
		detail.value = null
		return
	}
	try {
		detail.value = await api.getRequest(requestsStore.selectedId)
	} catch {
		showError(t('absence', 'Could not load the request'))
		emit('close')
		return
	}
	rejecting.value = false
	rejectComment.value = ''
	confirmingCancel.value = false
	applyDecideIntent()
}

/**
 * A 409 means the request is no longer what is on screen: show what it is now,
 * so the next click decides on that.
 *
 * @param error the failed call
 */
async function reloadIfChanged(error: unknown) {
	if (isAxiosError(error) && error.response?.status === 409) {
		await load()
	}
}

/**
 * Approves the request, or its withdrawal.
 */
async function approve() {
	if (!detail.value) {
		return
	}
	busy.value = true
	try {
		await requestsStore.approveRequest(detail.value.id, undefined, detail.value.updatedAt)
		emit('changed')
	} catch (e) {
		showError(serverMessage(e) || t('absence', 'Could not approve'))
		await reloadIfChanged(e)
	} finally {
		busy.value = false
	}
}

/**
 * "Decline" in a notification links here with `?decide=decline`, so the
 * reason box is already open when the sidebar arrives — the click that meant
 * "no" should not land on a screen that looks like a read-only summary. The
 * verdict itself is still the manager's to confirm, and the intent is
 * consumed from the URL so re-selecting another request does not inherit it.
 */
function applyDecideIntent() {
	if (route.query.decide !== 'decline') {
		return
	}
	router.replace({ path: route.path })
	if (detail.value?.canDecide && isDecidable.value) {
		rejecting.value = true
	}
}

/**
 * A day count without a trailing `.0`, so "22 days" not "22.0 days".
 *
 * @param value day count
 */
function fmtDays(value: number): string {
	const days = Math.round((Number(value) || 0) * 10) / 10
	return n('absence', '%n day', '%n days', days)
}

/**
 * Opens the box for the reason of a decline.
 */
function startReject() {
	rejecting.value = true
}

/**
 * Declines the request, or its withdrawal, with the reason typed.
 */
async function reject() {
	if (!detail.value) {
		return
	}
	busy.value = true
	try {
		await requestsStore.rejectRequest(detail.value.id, rejectComment.value, detail.value.updatedAt)
		emit('changed')
	} catch (e) {
		showError(serverMessage(e) || t('absence', 'Could not decline'))
		await reloadIfChanged(e)
	} finally {
		busy.value = false
	}
}

/**
 * Employees cancel straight away: their own request either has not been
 * approved yet or goes through a withdrawal approval anyway. HR's cancel is
 * final, so it asks first.
 */
function startCancel() {
	if (hrOverride.value) {
		confirmingCancel.value = true
		return
	}
	cancel()
}

/**
 * Cancels the request, or asks for its withdrawal once it is approved.
 */
async function cancel() {
	if (!detail.value) {
		return
	}
	busy.value = true
	try {
		await requestsStore.cancelRequest(detail.value.id)
		emit('changed')
	} catch (e) {
		showError(serverMessage(e) || t('absence', 'Could not cancel'))
	} finally {
		busy.value = false
	}
}

/**
 * Posts the comment typed and reloads the request.
 */
async function postComment() {
	if (!detail.value) {
		return
	}
	busy.value = true
	try {
		await api.addComment(detail.value.id, newComment.value)
		newComment.value = ''
		await load()
	} catch {
		showError(t('absence', 'Could not add comment'))
	} finally {
		busy.value = false
	}
}
</script>

<style scoped lang="scss">
.section {
	padding: calc(var(--default-grid-baseline, 4px) * 3);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.facts {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 6px 14px;
	margin: 0;

	dt {
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
	}

	dd {
		margin: 0;
		font-weight: 500;
	}

	&__decided {
		display: flex;
		align-items: center;
		gap: 6px;
	}

	&__muted {
		color: var(--color-text-maxcontrast);
		font-weight: 400;
	}

	// The balance is three facts, not one sentence: stack them so the headline
	// number stays the thing the eye lands on.
	&__balance {
		display: flex;
		flex-direction: column;
		gap: 1px;
	}
}

// hidden native input; the visible control is the NcButton
.attachments {
	margin-top: 16px;

	&__title {
		margin: 0 0 6px;
		font-size: 0.95rem;
	}

	&__hint {
		font-weight: normal;
		font-size: 0.75rem;
		color: var(--color-text-maxcontrast);
		margin-inline-start: 6px;
	}

	&__list {
		list-style: none;
		margin: 0 0 8px;
		padding: 0;
	}

	&__row {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	&__link {
		text-decoration: none;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;

		&:hover {
			text-decoration: underline;
		}
	}

	&__meta {
		color: var(--color-text-maxcontrast);
		font-size: 0.8rem;
		flex-shrink: 0;
	}

	&__input {
		display: none;
	}
}

.actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.confirm__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}

.reject {
	display: flex;
	flex-direction: column;
	gap: 8px;

	&__actions {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
	}
}

.comments {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__item {
		background: var(--color-background-hover);
		border-radius: var(--border-radius-large, 12px);
		padding: 10px 12px;

		p {
			margin: 6px 0 0;
		}
	}

	&__head {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 0.85rem;
	}
}

.comment-add {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-end;
}

.timeline {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 0;

	&__item {
		display: flex;
		gap: 12px;
		padding-bottom: 16px;
		position: relative;

		// connecting line between markers
		&:not(:last-child)::before {
			content: '';
			position: absolute;
			inset-inline-start: 13px;
			top: 26px;
			bottom: 0;
			width: 2px;
			background: var(--color-border);
		}
	}

	&__marker {
		flex: 0 0 auto;
		width: 28px;
		height: 28px;
		border-radius: 50%;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 0.95rem;
		background: var(--color-background-hover);
		z-index: 1;
	}

	&__body {
		flex: 1;
		min-width: 0;
	}

	&__head {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
		gap: 8px;
	}

	&__time {
		font-size: 0.78rem;
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}

	&__who {
		display: flex;
		align-items: center;
		gap: 6px;
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
		margin-top: 2px;
	}

	&__detail {
		margin: 6px 0 0;
		padding: 6px 10px;
		background: var(--color-background-hover);
		border-radius: var(--border-radius, 8px);
		font-size: 0.88rem;
		white-space: pre-wrap;
		overflow-wrap: anywhere;
	}
}
</style>
