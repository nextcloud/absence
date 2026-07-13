<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Animated donut ring showing used vs. remaining days for one leave type (§15.2).
-->
<template>
	<div class="ring" role="group" :aria-label="ariaLabel">
		<svg viewBox="0 0 120 120" class="ring__svg">
			<circle class="ring__track" cx="60" cy="60" :r="radius" />
			<circle class="ring__used"
				cx="60"
				cy="60"
				:r="radius"
				:stroke="row.typeColor"
				:stroke-dasharray="circumference"
				:stroke-dashoffset="usedOffset"
				transform="rotate(-90 60 60)" />
			<text x="60" y="54" class="ring__value">{{ remainingLabel }}</text>
			<text x="60" y="74" class="ring__unit">{{ t('absence', 'left') }}</text>
		</svg>
		<div class="ring__label">
			<span class="ring__icon" aria-hidden="true">{{ row.typeIcon }}</span>
			<span class="ring__name">{{ row.typeLabel }}</span>
		</div>
		<div class="ring__meta">
			<template v-if="row.entitlement !== null">
				{{ t('absence', '{used} of {total} used', { used: format(row.used), total: format(row.entitlement) }) }}
			</template>
			<template v-else>
				{{ t('absence', '{used} taken', { used: format(row.used) }) }}
			</template>
			<span v-if="row.pending > 0" class="ring__pending">· {{ t('absence', '{n} pending', { n: format(row.pending) }) }}</span>
		</div>
	</div>
</template>

<script>
export default {
	name: 'BalanceRing',
	props: {
		row: { type: Object, required: true },
	},
	data() {
		return {
			radius: 50,
			animated: false,
			tween: 0,
		}
	},
	computed: {
		circumference() {
			return 2 * Math.PI * this.radius
		},
		fraction() {
			if (!this.row.entitlement || this.row.entitlement <= 0) {
				return this.row.used > 0 ? 1 : 0
			}
			return Math.min(1, Math.max(0, this.row.used / this.row.entitlement))
		},
		usedOffset() {
			// Animate from full (nothing drawn) to the used fraction.
			return this.animated ? this.circumference * (1 - this.fraction) : this.circumference
		},
		targetValue() {
			if (this.row.remaining !== null && this.row.remaining !== undefined) {
				return Number(this.row.remaining)
			}
			return Number(this.row.used)
		},
		remainingLabel() {
			return this.format(this.tween)
		},
		ariaLabel() {
			return `${this.row.typeLabel}: ${this.remainingLabel} ${this.t('absence', 'days left')}`
		},
	},
	mounted() {
		if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
			this.animated = true
			this.tween = this.targetValue
			return
		}
		requestAnimationFrame(() => { this.animated = true })
		this.countUp()
	},

	methods: {
		countUp() {
			const target = this.targetValue
			const duration = 900
			const start = performance.now()
			const step = (now) => {
				const p = Math.min(1, (now - start) / duration)
				// easeOutCubic
				const eased = 1 - Math.pow(1 - p, 3)
				this.tween = Math.round(target * eased * 10) / 10
				if (p < 1) {
					requestAnimationFrame(step)
				} else {
					this.tween = target
				}
			}
			requestAnimationFrame(step)
		},
		format(v) {
			return Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 })
		},
	},
}
</script>

<style scoped lang="scss">
.ring {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
	padding: calc(var(--default-grid-baseline, 4px) * 3);

	&__svg {
		width: 120px;
		height: 120px;
	}

	&__track {
		fill: none;
		stroke: var(--color-background-darker, var(--color-border));
		stroke-width: 10;
	}

	&__used {
		fill: none;
		stroke-width: 10;
		stroke-linecap: round;
		transition: stroke-dashoffset 900ms cubic-bezier(0.4, 0, 0.2, 1);
	}

	&__value {
		font-size: 26px;
		font-weight: 700;
		text-anchor: middle;
		fill: var(--color-main-text);
	}

	&__unit {
		font-size: 11px;
		text-anchor: middle;
		fill: var(--color-text-maxcontrast);
		text-transform: uppercase;
		letter-spacing: 0.06em;
	}

	&__label {
		display: flex;
		align-items: center;
		gap: 6px;
		font-weight: 600;
	}

	&__meta {
		font-size: 0.82rem;
		color: var(--color-text-maxcontrast);
	}

	&__pending {
		color: var(--color-warning-text, var(--color-warning));
	}
}
</style>
