<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - HR reminds the people without a holiday region code (§7.2). Shows the
  - notification they will get, who gets it and who was reminded this week
  - already, before sending.
-->
<template>
	<NcDialog
		:name="t('absence', 'Remind people about their region code')"
		:buttons="buttons"
		size="normal"
		@update:open="(open) => !open && $emit('close')">
		<SkeletonList v-if="!plan" />
		<div v-else class="reminder">
			<figure class="reminder__preview">
				<figcaption class="reminder__label">
					{{ t('absence', 'What they will see') }}
				</figcaption>
				<div v-for="preview in previews" :key="preview.key" class="reminder__notification">
					<img class="reminder__icon" :src="appIcon" alt="">
					<div>
						<strong>{{ preview.subject }}</strong>
						<p>{{ preview.message }}</p>
					</div>
				</div>
			</figure>

			<section v-if="plan.send.length" class="reminder__group">
				<h3 class="reminder__title">
					{{ t('absence', 'Notified') }}
					<span class="reminder__count reminder__count--send">{{ plan.send.length }}</span>
				</h3>
				<ul class="reminder__people">
					<li v-for="person in plan.send" :key="person.uid" class="reminder__person">
						<NcAvatar
							:user="person.uid"
							:displayName="person.name"
							:size="24"
							:disableMenu="true"
							hideStatus />
						<span>{{ person.name }}</span>
						<span v-if="person.code" class="reminder__code">{{ regionFlag(person.code) }} {{ person.code }}</span>
					</li>
				</ul>
			</section>
			<NcNoteCard v-else type="success">
				{{ t('absence', 'Nobody to remind right now: everyone without a complete region code was reminded in the last 7 days.') }}
			</NcNoteCard>

			<section v-if="plan.recent.length" class="reminder__group reminder__group--muted">
				<h3 class="reminder__title">
					{{ t('absence', 'Reminded recently, left out') }}
					<span class="reminder__count">{{ plan.recent.length }}</span>
				</h3>
				<ul class="reminder__people">
					<li v-for="person in plan.recent" :key="person.uid" class="reminder__person">
						<NcAvatar
							:user="person.uid"
							:displayName="person.name"
							:size="24"
							:disableMenu="true"
							hideStatus />
						<span>{{ person.name }}</span>
						<span v-if="person.code" class="reminder__code">{{ regionFlag(person.code) }} {{ person.code }}</span>
						<span class="reminder__when">{{ ago(person.remindedAt) }}</span>
					</li>
				</ul>
			</section>

			<p class="reminder__footer">
				{{ t('absence', 'The notification disappears by itself once their code is complete. Nobody is reminded more than once a week.') }}
			</p>
		</div>
	</NcDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { imagePath } from '@nextcloud/router'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import SkeletonList from './SkeletonList.vue'
import api from '../api.js'
import { regionFlag } from '../utils/regions.js'

export default {
	name: 'RegionReminderDialog',
	components: { NcAvatar, NcDialog, NcNoteCard, SkeletonList },
	emits: ['close'],

	data() {
		return {
			plan: null,
			sending: false,
			appIcon: imagePath('absence', 'app-dark.svg'),
		}
	},

	computed: {
		/** The notification texts the people in this round get, as the server words them */
		previews() {
			const people = this.plan.send.length ? this.plan.send : this.plan.recent
			const previews = []
			if (people.some((person) => !person.code)) {
				previews.push({
					key: 'missing',
					subject: t('absence', 'Please set your holiday region code'),
					message: t('absence', 'HR asks you to set it on your profile: it decides which public holidays are days off for you.'),
				})
			}
			const incomplete = people.find((person) => person.code)
			if (incomplete) {
				previews.push({
					key: 'incomplete',
					subject: t('absence', 'Please complete your holiday region code'),
					message: t('absence', 'HR asks you to add your region on your profile: {code} has regional holidays, so a code like {example} decides which days are off for you.', { code: incomplete.code, example: incomplete.example }),
				})
			}
			return previews
		},

		buttons() {
			const count = this.plan?.send.length ?? 0
			if (count === 0) {
				return [{ label: t('absence', 'Close'), callback: () => {} }]
			}
			return [
				{ label: t('absence', 'Cancel'), callback: () => {} },
				{
					label: n('absence', 'Remind {count} person', 'Remind {count} people', count, { count }),
					variant: 'primary',
					disabled: this.sending,
					callback: () => this.send(),
				},
			]
		},
	},

	async mounted() {
		try {
			this.plan = await api.remindMissingRegion(true)
		} catch (e) {
			showError(e.response?.data?.message || t('absence', 'Could not load who to remind'))
			this.$emit('close')
		}
	},

	methods: {
		t,
		n,
		regionFlag,

		/**
		 * "today", "yesterday", "3 days ago"
		 *
		 * @param {string} iso when the person was reminded
		 */
		ago(iso) {
			const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000)
			return new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(-days, 'day')
		},

		async send() {
			this.sending = true
			try {
				const sent = await api.remindMissingRegion(false)
				showSuccess(n('absence', 'Reminder sent to {count} person', 'Reminder sent to {count} people', sent.send.length, { count: sent.send.length }))
			} catch (e) {
				showError(e.response?.data?.message || t('absence', 'Could not send the reminders'))
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.reminder {
	display: flex;
	flex-direction: column;
	gap: 16px;

	&__preview {
		margin: 0;
	}

	&__label {
		margin-bottom: 6px;
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
	}

	&__notification {
		display: flex;
		margin-bottom: 8px;
		gap: 12px;
		padding: 12px 14px;
		border-radius: var(--border-radius-large);
		background: var(--color-background-hover);
		box-shadow: 0 1px 3px var(--color-box-shadow);

		p {
			margin: 2px 0 0;
			color: var(--color-text-maxcontrast);
		}
	}

	&__icon {
		width: 32px;
		height: 32px;
		flex-shrink: 0;
	}

	&__group {
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		padding: 10px 12px;

		&--muted {
			opacity: 0.75;
		}
	}

	&__title {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0 0 8px;
		font-size: 1rem;
		font-weight: 600;
	}

	&__count {
		padding: 0 8px;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-dark);
		font-size: 0.85rem;

		&--send {
			color: var(--color-primary-element-text);
			background: var(--color-primary-element);
		}
	}

	&__people {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
		max-height: 200px;
		overflow-y: auto;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__person {
		display: flex;
		align-items: center;
		gap: 6px;
		padding: 2px 10px 2px 2px;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-hover);
	}

	&__code {
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
	}

	&__when {
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
	}

	&__footer {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;
	}
}
</style>
