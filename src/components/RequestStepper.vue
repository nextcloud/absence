<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Compact horizontal progress stepper for a request: Requested → Review → Outcome.
-->
<template>
	<ol class="stepper" :aria-label="t('absence', 'Request progress')">
		<li
			v-for="(step, i) in steps"
			:key="i"
			class="stepper__step"
			:class="[`stepper__step--${step.state}`, `stepper__step--${step.tone}`]">
			<span class="stepper__dot" aria-hidden="true">{{ step.icon }}</span>
			<span class="stepper__label">{{ step.label }}</span>
			<span v-if="i < steps.length - 1" class="stepper__bar" aria-hidden="true" />
		</li>
	</ol>
</template>

<script>
import { t } from '@nextcloud/l10n'

export default {
	name: 'RequestStepper',
	props: {
		status: { type: String, required: true },
	},

	computed: {
		steps() {
			const s = this.status
			const requested = { label: t('absence', 'Requested'), state: 'done', tone: 'default', icon: '📝' }

			let review
			if (['PENDING', 'ESCALATED', 'WITHDRAWAL_PENDING'].includes(s)) {
				review = { label: s === 'ESCALATED' ? t('absence', 'With HR') : t('absence', 'In review'), state: 'current', tone: 'default', icon: '⏳' }
			} else {
				review = { label: t('absence', 'Reviewed'), state: 'done', tone: 'default', icon: '👀' }
			}

			let outcome
			switch (s) {
				case 'APPROVED':
					outcome = { label: t('absence', 'Approved'), state: 'done', tone: 'success', icon: '✅' }
					break
				case 'REJECTED':
					outcome = { label: t('absence', 'Declined'), state: 'done', tone: 'error', icon: '✋' }
					break
				case 'CANCELLED':
					outcome = { label: t('absence', 'Cancelled'), state: 'done', tone: 'muted', icon: '🚫' }
					break
				case 'WITHDRAWAL_PENDING':
					outcome = { label: t('absence', 'Withdrawing'), state: 'current', tone: 'default', icon: '↩️' }
					break
				default:
					outcome = { label: t('absence', 'Decision'), state: 'future', tone: 'default', icon: '•' }
			}

			return [requested, review, outcome]
		},
	},

	methods: { t },
}
</script>

<style scoped lang="scss">
.stepper {
	display: flex;
	list-style: none;
	margin: 0;
	padding: 0;

	&__step {
		position: relative;
		flex: 1;
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 4px;
		--tone: var(--color-primary-element);
	}

	&__step--success { --tone: var(--color-success); }
	&__step--error { --tone: var(--color-error); }
	&__step--muted { --tone: var(--color-text-maxcontrast); }

	&__dot {
		width: 30px;
		height: 30px;
		border-radius: 50%;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 0.95rem;
		background: var(--color-background-dark);
		border: 2px solid var(--color-border);
		z-index: 1;
		transition: transform 200ms ease;
	}

	&__label {
		font-size: 0.75rem;
		text-align: center;
		color: var(--color-text-maxcontrast);
	}

	&__bar {
		position: absolute;
		top: 15px;
		left: 50%;
		width: 100%;
		height: 2px;
		background: var(--color-border);
		z-index: 0;
	}

	// done / current styling
	&__step--done &__dot,
	&__step--current &__dot {
		background: color-mix(in srgb, var(--tone) 18%, var(--color-main-background));
		border-color: var(--tone);
	}
	&__step--done &__label,
	&__step--current &__label {
		color: var(--color-main-text);
		font-weight: 600;
	}
	&__step--current &__dot {
		transform: scale(1.08);
		box-shadow: 0 0 0 4px color-mix(in srgb, var(--tone) 20%, transparent);
	}
	&__step--done &__bar {
		background: var(--tone);
	}
}

@media (prefers-reduced-motion: reduce) {
	.stepper__dot { transition: none; }
}
</style>
