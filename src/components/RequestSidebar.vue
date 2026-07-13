<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAppSidebar v-if="detail"
		:name="type.label"
		:subname="rangeLabel"
		@close="$emit('close')">
		<template v-if="showStatus" #description>
			<StatusChip :status="detail.status" />
		</template>

		<NcAppSidebarTab id="details" :name="t('absence', 'Details')" :order="1">
			<template #icon><InformationOutline :size="20" /></template>
			<div class="section">
				<RequestStepper v-if="showStatus" :status="detail.status" class="section__stepper" />
				<dl class="facts">
					<dt>{{ t('absence', 'Employee') }}</dt>
					<dd>{{ detail.employeeUid }}</dd>
					<dt>{{ t('absence', 'Type') }}</dt>
					<dd><LeaveTypeChip :type-id="detail.typeId" /></dd>
					<dt>{{ t('absence', 'Dates') }}</dt>
					<dd>{{ rangeLabel }}</dd>
					<dt>{{ t('absence', 'Working days') }}</dt>
					<dd>{{ detail.workingDays }}</dd>
					<template v-if="detail.replacementUid">
						<dt>{{ t('absence', 'Replacement') }}</dt>
						<dd class="facts__decided">
							<NcAvatar :user="detail.replacementUid" :size="20" :show-user-status="false" />
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
							<NcAvatar :user="detail.decidedBy" :size="20" :show-user-status="false" />
							{{ detail.decidedBy }}<span v-if="decidedAtLabel" class="facts__muted"> · {{ decidedAtLabel }}</span>
						</dd>
					</template>
					<template v-if="detail.decisionComment">
						<dt>{{ t('absence', 'Decision note') }}</dt>
						<dd>{{ detail.decisionComment }}</dd>
					</template>
				</dl>

				<div class="actions">
					<template v-if="detail.canDecide && isDecidable">
						<NcButton type="success" :disabled="busy" @click="approve">
							<template #icon><Check :size="20" /></template>
							{{ decideLabelApprove }}
						</NcButton>
						<NcButton type="error" :disabled="busy" @click="startReject">
							<template #icon><Close :size="20" /></template>
							{{ decideLabelReject }}
						</NcButton>
					</template>
					<template v-if="detail.canModify && isModifiable">
						<NcButton v-if="canEdit" type="secondary" :disabled="busy" @click="$emit('edit', detail)">
							<template #icon><Pencil :size="20" /></template>
							{{ t('absence', 'Edit') }}
						</NcButton>
						<NcButton type="tertiary" :disabled="busy" @click="cancel">
							<template #icon><CancelIcon :size="20" /></template>
							{{ cancelLabel }}
						</NcButton>
					</template>
				</div>

				<div v-if="rejecting" class="reject">
					<NcTextArea v-model="rejectComment"
						:label="t('absence', 'Reason for declining')"
						rows="2" />
					<div class="reject__actions">
						<NcButton type="tertiary" @click="rejecting = false">
							{{ t('absence', 'Back') }}
						</NcButton>
						<NcButton type="error" :disabled="rejectComment.trim() === '' || busy" @click="reject">
							{{ t('absence', 'Confirm decline') }}
						</NcButton>
					</div>
				</div>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab v-if="detail.coverage" id="coverage" :name="t('absence', 'Coverage')" :order="2">
			<template #icon><AccountGroup :size="20" /></template>
			<CoveragePanel :coverage="detail.coverage" />
		</NcAppSidebarTab>

		<NcAppSidebarTab id="comments" :name="t('absence', 'Comments')" :order="3">
			<template #icon><CommentOutline :size="20" /></template>
			<div class="section">
				<ul v-if="detail.comments.length" class="comments">
					<li v-for="c in detail.comments" :key="c.id" class="comments__item">
						<div class="comments__head">
							<NcAvatar :user="c.authorUid" :size="24" :show-user-status="false" />
							<strong>{{ c.authorUid }}</strong>
						</div>
						<p>{{ c.body }}</p>
					</li>
				</ul>
				<NcEmptyContent v-else :name="t('absence', 'No comments yet')" :description="t('absence', 'Start the conversation below.')">
					<template #icon><CommentOutline :size="20" /></template>
				</NcEmptyContent>
				<div class="comment-add">
					<NcTextArea v-model="newComment" :placeholder="t('absence', 'Add a comment…')" rows="2" />
					<NcButton type="secondary" :disabled="newComment.trim() === '' || busy" @click="postComment">
						{{ t('absence', 'Send') }}
					</NcButton>
				</div>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="history" :name="t('absence', 'History')" :order="4">
			<template #icon><History :size="20" /></template>
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
								<template v-if="ev.actorUid === 'system'">{{ t('absence', 'Automatically') }}</template>
								<template v-else>
									<NcAvatar :user="ev.actorUid" :size="20" :show-user-status="false" />
									{{ ev.actorUid }}
								</template>
							</div>
							<p v-if="ev.detail" class="timeline__detail">{{ ev.detail }}</p>
						</div>
					</li>
				</ol>
				<NcEmptyContent v-else :name="t('absence', 'No history yet')">
					<template #icon><History :size="20" /></template>
				</NcEmptyContent>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import CommentOutline from 'vue-material-design-icons/CommentOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import StatusChip from './StatusChip.vue'
import LeaveTypeChip from './LeaveTypeChip.vue'
import CoveragePanel from './CoveragePanel.vue'
import RequestStepper from './RequestStepper.vue'
import { store } from '../store.js'
import api from '../api.js'
import { formatRange } from '../utils/dates.js'

export default {
	name: 'RequestSidebar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcAvatar,
		NcButton,
		NcTextArea,
		NcEmptyContent,
		StatusChip,
		LeaveTypeChip,
		CoveragePanel,
		RequestStepper,
		InformationOutline,
		AccountGroup,
		CommentOutline,
		History,
		Check,
		Close,
		Pencil,
		CancelIcon,
	},
	emits: ['close', 'edit', 'changed'],
	data() {
		return {
			detail: null,
			busy: false,
			rejecting: false,
			rejectComment: '',
			newComment: '',
		}
	},
	computed: {
		type() {
			return this.detail ? store.leaveType(this.detail.typeId) : {}
		},
		rangeLabel() {
			return this.detail ? formatRange(this.detail.startDate, this.detail.endDate) : ''
		},
		decidedAtLabel() {
			return this.detail && this.detail.decidedAt ? this.formatDateTime(this.detail.decidedAt) : ''
		},
		showStatus() {
			return this.detail ? store.statusVisible(this.detail) : true
		},
		isDecidable() {
			return ['PENDING', 'ESCALATED', 'WITHDRAWAL_PENDING'].includes(this.detail.status)
		},
		isModifiable() {
			return !['REJECTED', 'CANCELLED'].includes(this.detail.status)
		},
		canEdit() {
			return ['PENDING', 'ESCALATED', 'APPROVED'].includes(this.detail.status)
		},
		isWithdrawal() {
			return this.detail.status === 'WITHDRAWAL_PENDING'
		},
		decideLabelApprove() {
			return this.isWithdrawal ? t('absence', 'Approve withdrawal') : t('absence', 'Approve')
		},
		decideLabelReject() {
			return this.isWithdrawal ? t('absence', 'Keep leave') : t('absence', 'Decline')
		},
		cancelLabel() {
			return this.detail.status === 'APPROVED' ? t('absence', 'Request withdrawal') : t('absence', 'Cancel request')
		},
	},
	mounted() {
		// App.vue remounts this component (via :key="store.selectedId") whenever the
		// selection changes, so loading once on mount always reflects the current id.
		this.load()
	},
	methods: {
		t,
		eventMeta(type) {
			const map = {
				request_created: { label: t('absence', 'Requested'), icon: '📝' },
				request_updated: { label: t('absence', 'Edited'), icon: '✏️' },
				request_edited_superseding: { label: t('absence', 'Edited (needs re-approval)'), icon: '✏️' },
				request_hr_edited: { label: t('absence', 'Adjusted by HR'), icon: '🛠️' },
				withdrawal_requested: { label: t('absence', 'Withdrawal requested'), icon: '↩️' },
				request_cancelled: { label: t('absence', 'Cancelled'), icon: '🚫' },
				withdrawal_approved: { label: t('absence', 'Withdrawal approved'), icon: '↩️' },
				request_approved: { label: t('absence', 'Approved'), icon: '✅' },
				request_rejected: { label: t('absence', 'Declined'), icon: '✋' },
				withdrawal_rejected: { label: t('absence', 'Withdrawal declined'), icon: '✋' },
				request_escalated: { label: t('absence', 'Escalated to HR'), icon: '⏫' },
				comment_added: { label: t('absence', 'Comment'), icon: '💬' },
			}
			return map[type] || { label: type, icon: '•' }
		},
		formatDateTime(iso) {
			if (!iso) {
				return ''
			}
			return new Date(iso).toLocaleString(undefined, {
				year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
			})
		},
		async load() {
			if (!store.selectedId) {
				this.detail = null
				return
			}
			try {
				this.detail = await api.getRequest(store.selectedId)
				this.rejecting = false
				this.rejectComment = ''
			} catch (e) {
				showError(t('absence', 'Could not load the request'))
				this.$emit('close')
			}
		},
		async approve() {
			this.busy = true
			try {
				await store.approveRequest(this.detail.id)
				this.$emit('changed')
			} catch (e) {
				showError(e.response?.data?.message || t('absence', 'Could not approve'))
			} finally {
				this.busy = false
			}
		},
		startReject() {
			this.rejecting = true
		},
		async reject() {
			this.busy = true
			try {
				await store.rejectRequest(this.detail.id, this.rejectComment)
				this.$emit('changed')
			} catch (e) {
				showError(e.response?.data?.message || t('absence', 'Could not decline'))
			} finally {
				this.busy = false
			}
		},
		async cancel() {
			this.busy = true
			try {
				await store.cancelRequest(this.detail.id)
				this.$emit('changed')
			} catch (e) {
				showError(e.response?.data?.message || t('absence', 'Could not cancel'))
			} finally {
				this.busy = false
			}
		},
		async postComment() {
			this.busy = true
			try {
				await api.addComment(this.detail.id, this.newComment)
				this.newComment = ''
				await this.load()
			} catch (e) {
				showError(t('absence', 'Could not add comment'))
			} finally {
				this.busy = false
			}
		},
	},
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
}

.actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
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
			left: 13px;
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
