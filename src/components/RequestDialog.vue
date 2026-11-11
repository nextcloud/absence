<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Create / edit a leave request with a live working-day + balance preview (§15.2).
-->
<template>
	<NcModal
		:name="dialogTitle"
		size="normal"
		@close="$emit('close')">
		<div class="dialog">
			<h2 class="dialog__title">
				{{ dialogTitle }}
			</h2>

			<NcNoteCard v-if="hrMode" type="info">
				{{ t('absence', 'Record an absence on behalf of an employee (e.g. sick leave). It is booked directly, without an approval step.') }}
			</NcNoteCard>

			<div v-if="hrMode" class="dialog__field">
				<label class="dialog__label">{{ t('absence', 'Employee') }}</label>
				<!-- eslint-disable @nextcloud/no-deprecated-library-props -- NcSelectUsers migration deferred: needs live-instance testing -->
				<NcSelect
					v-model="selectedEmployee"
					:options="employeeOptions"
					:loading="employeeLoading"
					:userSelect="true"
					label="displayName"
					:filterable="false"
					:placeholder="t('absence', 'Search for an employee…')"
					:aria-label-combobox="t('absence', 'Employee')"
					@search="onEmployeeSearch" />
				<!-- eslint-enable @nextcloud/no-deprecated-library-props -->
			</div>

			<div class="dialog__field">
				<label class="dialog__label">{{ t('absence', 'Leave type') }}</label>
				<NcSelect
					v-model="selectedType"
					:options="typeOptions"
					label="label"
					:clearable="false"
					:aria-label-combobox="t('absence', 'Leave type')">
					<template #option="{ icon, label }">
						<span class="opt"><span class="opt__icon">{{ icon }}</span>{{ label }}</span>
					</template>
					<template #selected-option="{ icon, label }">
						<span class="opt"><span class="opt__icon">{{ icon }}</span>{{ label }}</span>
					</template>
				</NcSelect>
			</div>

			<div v-if="showSickCategories" class="dialog__field">
				<label class="dialog__label">{{ t('absence', 'Category') }}</label>
				<NcSelect
					v-model="sickCategory"
					:options="sickCategoryOptions"
					label="label"
					:clearable="false"
					:aria-label-combobox="t('absence', 'Category')">
					<template #option="{ icon, label }">
						<span class="opt"><span class="opt__icon">{{ icon }}</span>{{ label }}</span>
					</template>
					<template #selected-option="{ icon, label }">
						<span class="opt"><span class="opt__icon">{{ icon }}</span>{{ label }}</span>
					</template>
				</NcSelect>
				<p class="dialog__hint">
					{{ t('absence', 'Only HR ever sees this category — everyone else, including the employee, sees a neutral absence.') }}
				</p>
			</div>

			<div v-if="showDisability" class="dialog__field">
				<NcCheckboxRadioSwitch v-model="disability">
					{{ t('absence', 'Disability-related leave') }}
				</NcCheckboxRadioSwitch>
				<p class="dialog__hint">
					{{ t('absence', 'For the additional statutory entitlement. Only HR can set and see this flag.') }}
				</p>
			</div>

			<div v-if="needsReplacement" class="dialog__field">
				<label class="dialog__label">
					{{ t('absence', 'Replacement') }}<span v-if="replacementRequired" class="dialog__req">*</span>
					<span v-else class="dialog__optional">{{ t('absence', '(optional)') }}</span>
				</label>
				<!-- eslint-disable @nextcloud/no-deprecated-library-props -- NcSelectUsers migration deferred: needs live-instance testing -->
				<NcSelect
					v-model="selectedReplacement"
					:options="replacementOptions"
					:loading="replacementLoading"
					:userSelect="true"
					label="displayName"
					:filterable="false"
					:placeholder="replacementPlaceholder"
					:aria-label-combobox="t('absence', 'Replacement')"
					@search="onReplacementSearch" />
				<!-- eslint-enable @nextcloud/no-deprecated-library-props -->
				<p class="dialog__hint">
					{{ replacementHint }}
				</p>
			</div>

			<div class="dialog__row">
				<div class="dialog__field">
					<label class="dialog__label">{{ t('absence', 'From') }}</label>
					<NcDateTimePickerNative v-model="startDate" type="date" />
				</div>
				<div class="dialog__field">
					<label class="dialog__label">{{ t('absence', 'To') }}</label>
					<NcDateTimePickerNative v-model="endDate" type="date" />
				</div>
			</div>

			<div class="dialog__field">
				<label class="dialog__label">
					{{ t('absence', 'Working days') }}<span class="dialog__req">*</span>
				</label>
				<NcTextField
					:modelValue="workingDays"
					type="number"
					min="0"
					step="0.5"
					:label="t('absence', 'Working days')"
					:labelVisible="false"
					@update:modelValue="onWorkingDaysInput" />
				<p class="dialog__hint dialog__hint--estimate">
					<span aria-hidden="true">⚠️</span>
					<template v-if="prefillActive && !isOwnLeave">
						{{ t('absence', 'Prefilled from the working days and public holidays of {name}. This is only an estimate: always check the number and adjust it manually if it is off.', { name: subjectName }, undefined, { escape: false }) }}
					</template>
					<template v-else-if="prefillActive">
						{{ t('absence', 'Prefilled from your working days and public holidays. This is only an estimate: always check the number and adjust it manually if it is off.') }}
						<template v-if="!hrMode">
							{{ t('absence', 'Your manager will verify it.') }}
						</template>
						<a
							:href="settingsUrl"
							target="_blank"
							rel="noreferrer noopener"
							class="dialog__link">{{ t('absence', 'Check your working days') }}</a>
					</template>
					<template v-else>
						{{ t('absence', 'Number of working days this absence covers (excluding weekends and public holidays) — this is only an estimate: always check the number and adjust it manually if it is off.') }}
						<template v-if="!hrMode">
							{{ t('absence', 'Your manager will verify it.') }}
						</template>
					</template>
				</p>
			</div>

			<div v-if="balanceRow && !hrMode && workingDaysNum > 0" class="preview" :style="{ '--type-color': typeColor }">
				<div class="preview__balance">
					<span>{{ t('absence', 'Available') }}</span>
					<strong>{{ formatDays(balanceRow.available) }} → {{ formatDays(projectedAvailable) }}</strong>
					<span class="preview__bar" role="presentation">
						<span class="preview__bar-fill" :style="{ width: availablePct + '%' }" />
					</span>
				</div>
			</div>

			<NcNoteCard v-if="wouldGoNegative" type="warning">
				{{ t('absence', 'Heads up: this goes beyond your available balance. You can still submit — HR may approve it.') }}
			</NcNoteCard>

			<NcNoteCard v-if="overlapCount" :type="coverageTight ? 'warning' : 'info'">
				<template v-if="coverageTight">
					{{ n('absence', 'Heads up: %n colleague is already off then ({names}), so approving this would leave the team thin. You can still submit.', 'Heads up: %n colleagues are already off then ({names}), so approving this would leave the team thin. You can still submit.', overlapCount, { names: overlapLabel }) }}
				</template>
				<template v-else>
					{{ n('absence', '%n colleague is off then ({names}).', '%n colleagues are off then ({names}).', overlapCount, { names: overlapLabel }) }}
				</template>
			</NcNoteCard>

			<div class="dialog__field">
				<label class="dialog__label">
					{{ t('absence', 'Reason') }}
					<span v-if="requiresNote" class="dialog__req">*</span>
					<span v-else class="dialog__optional">{{ t('absence', '(optional)') }}</span>
				</label>
				<NcTextArea
					v-model="reason"
					:placeholder="t('absence', 'Optional note for your manager')"
					resize="vertical"
					rows="2" />
			</div>

			<div class="dialog__actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('absence', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSubmit || submitting" @click="submit">
					<template #icon>
						<NcLoadingIcon v-if="submitting" :size="20" />
						<Send v-else :size="20" />
					</template>
					{{ submitLabel }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script setup lang="ts">
import type {
	BalanceRow,
	ContractPeriod,
	Coverage,
	CoverageEvent,
	LeaveRequest,
	LeaveType,
	RequestCreateData,
	UserRef,
} from '../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Send from 'vue-material-design-icons/Send.vue'
import api from '../services/api.ts'
import { store } from '../store.ts'
import { weekdaysOn } from '../utils/contract.ts'
import { parseWeekdays, toIso } from '../utils/dates.ts'
import { serverMessage } from '../utils/errors.ts'
import { formatRegionCode, makeHolidayChecker } from '../utils/holidays.ts'
import { latestOnly } from '../utils/latest.ts'
import { formatNumber } from '../utils/locale.ts'
import { namesAndMore } from '../utils/people.ts'
import { isStoredRange, prefilledWorkingDays } from '../utils/prefill.ts'

/** An entry of the category picker under sick leave: a null id is plain sick leave */
interface SickCategoryOption {
	id: number | null
	icon: string
	label: string
}

const props = withDefaults(defineProps<{
	request?: LeaveRequest | null
	// HR "record absence for an employee" mode (e.g. sick leave).
	hrMode?: boolean
}>(), {
	request: null,
	hrMode: false,
})

const emit = defineEmits<{
	close: []
	saved: []
}>()

const selectedType = ref<LeaveType | null>(null)
const sickCategory = ref<SickCategoryOption | null>(null)
const disability = ref(false)
const startIso = ref<string | null>(null)
const endIso = ref<string | null>(null)
const workingDays = ref<string | number>('')
// True once the user edits the field by hand — stops auto-prefill from
// overwriting their number (§7: prefilled but manually changeable).
const workingDaysTouched = ref(false)
const holidayChecker = ref<((iso: string) => boolean) | null>(null)
// The contract periods of somebody else whose absence this is, once loaded.
const subjectPeriods = ref<ContractPeriod[] | null>(null)
const reason = ref('')
const submitting = ref(false)
const selectedEmployee = ref<UserRef | null>(null)
const employeeOptions = ref<UserRef[]>([])
const employeeLoading = ref(false)
const selectedReplacement = ref<UserRef | null>(null)
const replacementOptions = ref<UserRef[]>([])
const replacementLoading = ref(false)
// Who else on the team is off during the picked dates (§8), fetched as the
// dates change. `coverageSeq` discards answers overtaken by a newer range.
const coverage = ref<Coverage | null>(null)
let coverageSeq = 0
let coverageTimer: ReturnType<typeof setTimeout> | undefined

// HR picking one employee after another: only the last one's data may land.
const startSubjectLoad = latestOnly()

const isEdit = computed(() => props.request !== null)

const dialogTitle = computed(() => {
	if (props.hrMode) {
		return t('absence', 'Record absence')
	}
	return isEdit.value ? t('absence', 'Edit request') : t('absence', 'Request time off')
})

const submitLabel = computed(() => {
	if (isEdit.value) {
		return t('absence', 'Save changes')
	}
	// HR records directly — there is no request/approval flow.
	return props.hrMode ? t('absence', 'Record') : t('absence', 'Submit request')
})

/** The type keyed 'sick' that the confidential categories nest under. */
const sickType = computed<LeaveType | null>(() => store.enabledLeaveTypes.find((type) => type.key === 'sick' && type.enabled) || null)

const typeOptions = computed<LeaveType[]>(() => {
	// HR may record any enabled type (incl. sick); employees only self-requestable ones.
	// HR correcting an existing record needs the same full list — otherwise the
	// record's own type (sick) is missing from the picker it is preselected in.
	if (props.hrMode || (isEdit.value && store.session.isHr)) {
		// Confidential categories (hrOnly) are picked as a sub-option of sick
		// leave, not as top-level types — unless no sick type exists to hang
		// them off, in which case they stay listed directly.
		return store.enabledLeaveTypes.filter((type) => !type.hrOnly || !sickType.value)
	}
	return store.requestableLeaveTypes
})

/** Confidential (HR-only) types — the server only sends these to HR. */
const confidentialCategories = computed<LeaveType[]>(() => store.enabledLeaveTypes.filter((type) => type.hrOnly))

const showSickCategories = computed<boolean>(() => {
	return (props.hrMode || (isEdit.value && store.session.isHr === true))
		&& confidentialCategories.value.length > 0
		&& selectedType.value !== null
		&& sickType.value !== null
		&& selectedType.value.id === sickType.value.id
})

/**
 * The disability flag (§5.8) is HR's alone, and belongs to annual leave —
 * the additional statutory entitlement it marks is annual leave.
 */
const showDisability = computed<boolean>(() => {
	return (props.hrMode || (isEdit.value && store.session.isHr === true))
		&& selectedType.value !== null
		&& selectedType.value.key === 'annual'
})

const sickCategoryOptions = computed<[SickCategoryOption, ...SickCategoryOption[]]>(() => {
	return [
		{ id: null, icon: sickType.value ? sickType.value.icon : '🤒', label: t('absence', 'General sick leave') },
		...confidentialCategories.value.map((c) => ({ id: c.id, icon: c.icon, label: c.label })),
	]
})

const typeColor = computed(() => selectedType.value ? selectedType.value.color : 'var(--color-primary-element)')

const requiresNote = computed(() => selectedType.value ? selectedType.value.requiresNote : false)

const needsReplacement = computed(() => selectedType.value ? selectedType.value.requiresReplacement : false)

const subjectUid = computed<string | null>(() => {
	// Whose leave this is — that person can't be their own replacement.
	return props.hrMode ? (selectedEmployee.value && selectedEmployee.value.uid) : (props.request ? props.request.employeeUid : store.session.uid)
})

/** Whether this dialog is about the signed-in user's own leave. */
const isOwnLeave = computed(() => subjectUid.value === store.session.uid)

/**
 * Whose absence this is, for wording aimed at somebody else's record.
 * Empty until HR has picked an employee, which the strings below allow for.
 */
const subjectName = computed(() => {
	if (props.hrMode) {
		return selectedEmployee.value ? selectedEmployee.value.displayName : ''
	}
	return props.request ? (props.request.employeeName || props.request.employeeUid) : ''
})

/**
 * Demanded only of somebody arranging their own leave: they know who can cover
 * and are asked to sort it out before going (§5.1). HR recording an absence for
 * somebody else is stating a fact, often after it happened, and cannot nominate
 * cover on their behalf — so the field is offered there but never required.
 * Mirrors the same rule in RequestService::resolveReplacement().
 */
const replacementRequired = computed(() => needsReplacement.value && isOwnLeave.value)

const replacementPlaceholder = computed(() => {
	return isOwnLeave.value
		? t('absence', 'Who covers for you?')
		: t('absence', 'Who is the replacement?')
})

const replacementHint = computed(() => {
	if (isOwnLeave.value) {
		return t('absence', 'A colleague who covers your duties while you are away. They are notified once your leave is approved.')
	}
	return subjectName.value
		? t('absence', 'Optional. A colleague who covers for {name} while they are away, and is notified once this is recorded.', { name: subjectName.value })
		: t('absence', 'Optional. A colleague who covers these duties while this employee is away, and is notified once this is recorded.')
})

// Bridge the native date pickers (Date objects) to our ISO string state.
const startDate = computed<Date | null>({
	get() {
		return startIso.value ? new Date(startIso.value + 'T00:00:00') : null
	},

	set(v) {
		startIso.value = v ? toIso(v) : null
		// Keep the end on/after the start.
		if (startIso.value && endIso.value && endIso.value < startIso.value) {
			endIso.value = startIso.value
		}
	},
})

const endDate = computed<Date | null>({
	get() {
		return endIso.value ? new Date(endIso.value + 'T00:00:00') : null
	},

	set(v) {
		endIso.value = v ? toIso(v) : null
	},
})

const workingDaysNum = computed(() => {
	const v = parseFloat(String(workingDays.value))
	return Number.isFinite(v) ? v : 0
})

/**
 * The weekdays the absent person works: mine from the session, somebody
 * else's from their contract on the first day of the absence. Null when
 * nothing is known, and then nothing is prefilled.
 */
const subjectWeekdays = computed<Set<number> | null>(() => {
	return isOwnLeave.value
		? parseWeekdays(store.session.workWeekdays || '1,2,3,4,5')
		: weekdaysOn(subjectPeriods.value, startIso.value)
})

// The prefill note only shows while the value is a count made here: not
// once edited, and not for the stored count of a saved request.
const prefillActive = computed(() => {
	return !workingDaysTouched.value
		&& subjectWeekdays.value !== null
		&& !isStoredRange(props.request, startIso.value, endIso.value)
})

const settingsUrl = computed(() => generateUrl('/settings/user/availability'))

const balanceRow = computed<BalanceRow | null>(() => {
	// The store holds the current user's balance, so only meaningful for
	// self-service. Match the year the leave starts in — the balance list is
	// sorted newest-year first, so taking the first type match would show
	// next year's numbers as soon as any next-year data exists. When there is
	// no row for that year yet (e.g. booking far ahead), show no preview
	// rather than a wrong one.
	const type = selectedType.value
	if (!type || props.hrMode || !startIso.value) {
		return null
	}
	const year = parseInt(startIso.value.slice(0, 4), 10)
	return store.balance.balances.find((b) => b.typeId === type.id && b.year === year && b.entitlement !== null) || null
})

const projectedAvailable = computed<number | null>(() => {
	if (!balanceRow.value) {
		return null
	}
	return Math.round(((balanceRow.value.available ?? 0) - workingDaysNum.value) * 10) / 10
})

const wouldGoNegative = computed<boolean>(() => {
	return balanceRow.value !== null
		&& selectedType.value !== null
		&& selectedType.value.countsAgainstBalance
		&& projectedAvailable.value !== null
		&& projectedAvailable.value < 0
})

const availablePct = computed(() => {
	if (!balanceRow.value || !balanceRow.value.entitlement) {
		return 0
	}
	return Math.max(0, Math.min(100, ((projectedAvailable.value ?? 0) / balanceRow.value.entitlement) * 100))
})

/**
 * The coverage endpoint answers for *my* team, so the warning only means
 * something when the leave is my own. HR recording an absence for someone
 * else would otherwise be shown their own colleagues, which is worse than
 * showing nothing.
 */
const coverageApplies = computed(() => !props.hrMode && subjectUid.value === store.session.uid)

/** Colleagues off during the picked dates — excluding me and this very request. */
const overlaps = computed<CoverageEvent[]>(() => {
	if (!coverage.value) {
		return []
	}
	return coverage.value.events.filter((ev) => ev.employeeUid !== store.session.uid
		&& !(props.request && ev.requestId === props.request.id))
})

/** One entry per person: two requests from the same colleague are still one absence. */
const overlapNames = computed(() => [...new Set(overlaps.value.map((ev) => ev.displayName))])

const overlapCount = computed(() => overlapNames.value.length)

const overlapLabel = computed(() => {
	const names = overlapNames.value
	return namesAndMore({ count: names.length, names: names.slice(0, 3) })
})

/**
 * Whether submitting would push the team to the admin's concurrency limit —
 * the same arithmetic the manager sees when reviewing (CoverageService::
 * getRequestCoverage), shown here so it is known before the ask, not after.
 */
const coverageTight = computed(() => {
	if (!coverage.value || !coverage.value.threshold) {
		return false
	}
	return coverage.value.maxConcurrent + 1 >= coverage.value.threshold
})

const canSubmit = computed(() => {
	if (!selectedType.value || !startIso.value || !endIso.value || workingDaysNum.value <= 0) {
		return false
	}
	if (props.hrMode && !selectedEmployee.value) {
		return false
	}
	if (replacementRequired.value && !selectedReplacement.value) {
		return false
	}
	if (requiresNote.value && reason.value.trim() === '') {
		return false
	}
	return true
})

watch(selectedType, () => {
	// Leaving "Sick leave" abandons any picked confidential category;
	// arriving on it starts from the explicit default, never an empty field.
	if (!showSickCategories.value) {
		sickCategory.value = null
	} else if (!sickCategory.value) {
		sickCategory.value = sickCategoryOptions.value[0]
	}
})

watch(startIso, () => {
	recomputePrefill()
	scheduleCoverage()
})

watch(endIso, () => {
	recomputePrefill()
	scheduleCoverage()
})

watch(subjectUid, () => {
	loadSubject()
})

onBeforeUnmount(() => {
	clearTimeout(coverageTimer)
})

onMounted(async () => {
	await initFromProps()
	loadSubject()
	if (!props.hrMode && !store.balance.balances.length) {
		// No year on purpose: the server then answers every year, which balanceRow needs for leave booked in another year
		await store.loadMyBalance()
	}
})

/** Debounced: the dates move a lot while a picker is being dragged. */
function scheduleCoverage() {
	clearTimeout(coverageTimer)
	coverageTimer = setTimeout(() => loadCoverage(), 350)
}

/**
 * Load who else on my team is off during the picked dates.
 */
async function loadCoverage() {
	if (!coverageApplies.value || !startIso.value || !endIso.value || endIso.value < startIso.value) {
		coverage.value = null
		return
	}
	const seq = ++coverageSeq
	try {
		const data = await api.getCoverage(startIso.value, endIso.value, 'team')
		if (seq === coverageSeq) {
			coverage.value = data
		}
	} catch {
		// Advisory only — a failed lookup must never stand between somebody and
		// their holiday, so it just leaves the hint out.
		if (seq === coverageSeq) {
			coverage.value = null
		}
	}
}

/**
 * Take a number typed into the working-days field.
 *
 * @param v what the field holds now
 */
function onWorkingDaysInput(v: string | number) {
	workingDays.value = v
	workingDaysTouched.value = true
}

/** Prefill the working-day count from the picked range (see prefilledWorkingDays). */
function recomputePrefill() {
	const value = prefilledWorkingDays({
		request: props.request,
		touched: workingDaysTouched.value,
		startIso: startIso.value,
		endIso: endIso.value,
		weekdays: subjectWeekdays.value,
		isHoliday: holidayChecker.value,
	})
	if (value !== null) {
		workingDays.value = value
	}
}

/**
 * The working days and public holidays of the person whose absence this is.
 * Mine are in the session; somebody else's come from their profile. The
 * holidays load in the background and the prefill refreshes once they are
 * there: weekday counting works without them.
 */
async function loadSubject() {
	const isLatest = startSubjectLoad()
	subjectPeriods.value = null
	holidayChecker.value = null
	recomputePrefill()
	const uid = subjectUid.value
	if (!uid) {
		return
	}
	let code = formatRegionCode(store.session.holidayCountry ?? '', store.session.holidayRegion ?? '')
	if (!isOwnLeave.value) {
		try {
			const profile = await api.getProfile(uid)
			if (!isLatest()) {
				return
			}
			subjectPeriods.value = profile.contract.periods
			code = profile.regionCode
			recomputePrefill()
		} catch {
			if (isLatest()) {
				showError(t('absence', 'Could not load the working days of {name}', { name: subjectName.value }, undefined, { escape: false }))
			}
			return
		}
	}
	try {
		const year = new Date().getFullYear()
		const holidays = await api.listApplyingHolidays(code, `${year - 1}-01-01`, `${year + 1}-12-31`)
		if (isLatest()) {
			holidayChecker.value = makeHolidayChecker(holidays)
			recomputePrefill()
		}
	} catch {
		// Load failed: prefill stays weekday-only
	}
}

/**
 * A number of days for display.
 *
 * @param v the days, null or undefined when there is no figure
 */
function formatDays(v: number | null | undefined): string {
	if (v === null || v === undefined) {
		return '—'
	}
	return formatNumber(v)
}

/**
 * Fill the form from the request being edited, or with the defaults of a new one.
 */
async function initFromProps() {
	const types = typeOptions.value
	const request = props.request
	if (request) {
		const requestType = store.enabledLeaveTypes.find((x) => x.id === request.typeId) || null
		if (requestType && requestType.hrOnly && sickType.value) {
			// A confidential record edits as "Sick leave" + its category.
			selectedType.value = sickType.value
			sickCategory.value = { id: requestType.id, icon: requestType.icon, label: requestType.label }
		} else {
			selectedType.value = requestType || types[0] || null
		}
		disability.value = !!request.disability
		startIso.value = request.startDate
		endIso.value = request.endDate
		workingDays.value = String(request.workingDays)
		reason.value = request.reason || ''
		if (request.replacementUid) {
			selectedReplacement.value = { uid: request.replacementUid, displayName: request.replacementName || request.replacementUid }
		}
	} else {
		selectedType.value = types[0] || null
		const today = toIso(new Date())
		startIso.value = today
		endIso.value = today
		// Prefill the replacement used last time (self-service only).
		if (!props.hrMode) {
			selectedReplacement.value = pastReplacements()[0] || null
		}
	}
	// Offer colleagues used before so the dropdown isn't empty (self-service).
	if (!props.hrMode) {
		replacementOptions.value = pastReplacements()
		// Keep the current selection pickable even if it isn't in that list.
		const selected = selectedReplacement.value
		if (selected && !replacementOptions.value.some((o) => o.uid === selected.uid)) {
			replacementOptions.value = [selected, ...replacementOptions.value]
		}
	}
}

// Distinct colleagues used as replacement in the user's past requests, newest first.
/**
 * @return the colleagues, as options of the replacement picker
 */
function pastReplacements(): UserRef[] {
	const seen = new Set<string>()
	const list: UserRef[] = []
	const own = store.requests
		.filter((r) => r.employeeUid === store.session.uid && r.replacementUid)
		.sort((a, b) => b.id - a.id)
	for (const r of own) {
		const uid = r.replacementUid
		if (uid && !seen.has(uid)) {
			seen.add(uid)
			list.push({ uid, displayName: r.replacementName || uid })
		}
	}
	return list
}

/**
 * Search the employee HR records the absence for.
 *
 * @param query what was typed into the picker
 */
async function onEmployeeSearch(query: string) {
	if (!query || query.length < 2) {
		return
	}
	employeeLoading.value = true
	try {
		employeeOptions.value = await api.searchUsers(query)
	} catch {
		employeeOptions.value = []
	} finally {
		employeeLoading.value = false
	}
}

/**
 * Search the colleague who covers the absence.
 *
 * @param query what was typed into the picker
 */
async function onReplacementSearch(query: string) {
	if (!query || query.length < 2) {
		return
	}
	replacementLoading.value = true
	try {
		const users = await api.searchUsers(query)
		// A person can't be their own replacement.
		replacementOptions.value = users.filter((u) => u.uid !== subjectUid.value)
	} catch {
		replacementOptions.value = []
	} finally {
		replacementLoading.value = false
	}
}

/**
 * Create the request, or save the changes to the one being edited.
 */
async function submit() {
	const type = selectedType.value
	// canSubmit already implies the type and both dates: repeated to narrow them.
	if (!canSubmit.value || !type || !startIso.value || !endIso.value) {
		return
	}
	submitting.value = true
	const payload: RequestCreateData = {
		typeId: (showSickCategories.value && sickCategory.value && sickCategory.value.id !== null)
			? sickCategory.value.id
			: type.id,

		startDate: startIso.value,
		endDate: endIso.value,
		workingDays: workingDaysNum.value,
		reason: reason.value,
	}
	if (props.hrMode && selectedEmployee.value) {
		payload.employeeUid = selectedEmployee.value.uid
	}
	if (showDisability.value) {
		payload.disability = disability.value
	}
	if (needsReplacement.value && selectedReplacement.value) {
		payload.replacementUid = selectedReplacement.value.uid
	}
	try {
		if (props.request) {
			await store.updateRequest(props.request.id, payload)
		} else {
			await store.createRequest(payload)
		}
		emit('saved')
	} catch (e) {
		const message = serverMessage(e)
		showError(message || t('absence', 'Could not save the request'))
	} finally {
		submitting.value = false
	}
}
</script>

<style scoped lang="scss">
.dialog {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	padding: calc(var(--default-grid-baseline, 4px) * 5);

	&__title {
		margin: 0 0 4px;
		font-size: 1.3rem;
	}

	&__row {
		display: flex;
		gap: calc(var(--default-grid-baseline, 4px) * 3);
		flex-wrap: wrap;

		.dialog__field {
			flex: 1 1 140px;
		}
	}

	&__field {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__label {
		font-weight: 600;
		font-size: 0.9rem;
	}

	&__req {
		color: var(--color-error);
	}

	&__optional {
		font-weight: normal;
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
	}

	&__hint {
		margin: 4px 0 0;
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
	}

	// The working-day estimate warning carries the message, not just decoration:
	// the number is a guess until a human checked it, so it reads as a caution.
	&__hint--estimate {
		color: var(--color-warning-text);
	}

	&__link {
		text-decoration: underline;
	}

	&__actions {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		margin-top: 4px;
	}
}

.preview {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: calc(var(--default-grid-baseline, 4px) * 3);
	border-radius: var(--border-radius-large, 12px);
	background: color-mix(in srgb, var(--type-color) 12%, var(--color-main-background));
	border: 2px solid color-mix(in srgb, var(--type-color) 40%, transparent);

	&__days {
		display: flex;
		flex-direction: column;
		line-height: 1.1;
	}

	&__count {
		font-size: 1.8rem;
		font-weight: 700;
		color: var(--type-color);
	}

	&__caption {
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
	}

	&__balance {
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 4px;
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
	}

	&__bar {
		display: block;
		width: 120px;
		height: 6px;
		border-radius: 3px;
		background: var(--color-background-dark);
		overflow: hidden;
	}

	&__bar-fill {
		display: block;
		height: 100%;
		border-radius: 3px;
		background: var(--type-color);
		transition: width 300ms ease;
	}
}

.opt {
	display: inline-flex;
	align-items: center;
	gap: 8px;

	&__icon {
		font-size: 1.1em;
	}
}
</style>
