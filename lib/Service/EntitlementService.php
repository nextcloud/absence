<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\Entitlement;
use OCA\Absence\Db\EntitlementEvent;
use OCA\Absence\Db\EntitlementEventMapper;
use OCA\Absence\Db\EntitlementMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\NotFoundException;
use OCA\Absence\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Entitlement management for HR and the year-rollover / carry-over logic (§6).
 */
class EntitlementService {
	public function __construct(
		private EntitlementMapper $entitlementMapper,
		private EntitlementEventMapper $eventMapper,
		private LeaveTypeMapper $leaveTypeMapper,
		private BalanceService $balanceService,
		private ConfigService $config,
		private ClockService $clock,
		private ActivityPublisher $activity,
		private EmployeeDirectory $employees,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return Entitlement[]
	 */
	public function listForEmployee(string $employeeUid, ?int $year = null): array {
		return $this->entitlementMapper->findForEmployee($employeeUid, $year);
	}

	/**
	 * Update an entitlement row (HR). Manual adjustments require a note (§6.1).
	 *
	 * Two ways to move the manual adjustment, and the difference matters:
	 *
	 *  - `adjustmentDelta` **adds to** what is already there. This is how corrections
	 *    are actually made — "+2 for the wedding", later "−2, booked in error" — and
	 *    the two must cancel to nothing.
	 *  - `manualAdjustment` **sets** the running total outright, for the rare case of
	 *    overwriting it wholesale.
	 *
	 * Sending both is refused rather than guessed at.
	 *
	 * @param array{baseDays?:float,carryOverDays?:float,manualAdjustment?:float,adjustmentDelta?:float,adjustmentNote?:string} $data
	 */
	public function update(string $actorUid, int $id, array $data): Entitlement {
		try {
			$ent = $this->entitlementMapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l->t('Entitlement not found'));
		}
		if (array_key_exists('adjustmentDelta', $data) && array_key_exists('manualAdjustment', $data)) {
			throw new ValidationException($this->l->t('Send either an adjustment to apply or an absolute adjustment, not both.'));
		}
		// Read before any setter runs: these are what the history reports moving from.
		$before = [
			EntitlementEvent::FIELD_BASE_DAYS => $ent->getBaseDays(),
			EntitlementEvent::FIELD_CARRY_OVER_DAYS => $ent->getCarryOverDays(),
			EntitlementEvent::FIELD_MANUAL_ADJUSTMENT => $ent->getManualAdjustment(),
		];
		$note = trim((string)($data['adjustmentNote'] ?? ''));

		if (array_key_exists('baseDays', $data)) {
			$ent->setBaseDays((float)$data['baseDays']);
		}
		if (array_key_exists('carryOverDays', $data)) {
			$ent->setCarryOverDays((float)$data['carryOverDays']);
		}
		if (array_key_exists('adjustmentDelta', $data)) {
			// A correction on top of whatever corrections came before, so "+2" and a
			// later "−2" cancel and the allowance returns to where it started.
			// Assigning here instead — which is what the absolute branch below does —
			// made the second correction *replace* the first: 25 → +2 → 27, then a
			// −2 correction landed on 23 rather than back on 25.
			$delta = (float)$data['adjustmentDelta'];
			if (abs($delta) > 0.001) {
				if ($note === '') {
					throw new ValidationException($this->l->t('A note is required when adjusting an entitlement.'));
				}
				$ent->setManualAdjustment($ent->getManualAdjustment() + $delta);
				$ent->setAdjustmentNote($data['adjustmentNote'] ?? $ent->getAdjustmentNote());
			}
		} elseif (array_key_exists('manualAdjustment', $data)) {
			$adjustment = (float)$data['manualAdjustment'];
			if ($adjustment !== $ent->getManualAdjustment() && $note === '') {
				throw new ValidationException($this->l->t('A note is required when adjusting an entitlement.'));
			}
			$ent->setManualAdjustment($adjustment);
			$ent->setAdjustmentNote($data['adjustmentNote'] ?? $ent->getAdjustmentNote());
		}
		$ent->setUpdatedAt($this->clock->now());
		$ent = $this->entitlementMapper->update($ent);

		$changes = $this->recordChanges($actorUid, $ent, $before, $note);

		$this->activity->publish(ActivityPublisher::SUBJECT_BALANCE_ADJUSTED, [
			'employee' => $ent->getEmployeeUid(),
			'year' => $ent->getYear(),
			// Carried so the activity entry can say what happened rather than only
			// that something did. Empty when a save changed no figure.
			'summary' => $this->summarise($changes),
			'note' => $note,
		], [$ent->getEmployeeUid(), $actorUid]);
		$this->logger->info('Absence action: entitlement_updated', [
			'app' => 'absence',
			'action' => 'entitlement_updated',
			'actor' => $actorUid,
			'employee' => $ent->getEmployeeUid(),
			'year' => $ent->getYear(),
			'typeId' => $ent->getTypeId(),
			'baseDays' => $ent->getBaseDays(),
			'carryOverDays' => $ent->getCarryOverDays(),
			'manualAdjustment' => $ent->getManualAdjustment(),
		]);
		return $ent;
	}

	/**
	 * Create (or fetch) the entitlement row for a single employee and apply the
	 * given values (HR). Unlike bulkSet() this never touches other employees.
	 *
	 * @param array{baseDays?:float,carryOverDays?:float,manualAdjustment?:float,adjustmentNote?:string} $data
	 */
	public function setForEmployee(string $actorUid, string $employeeUid, int $year, int $typeId, array $data): Entitlement {
		// Also rejects guests: they have no entitlement to set (§2.2).
		if (!$this->employees->isEmployee($employeeUid)) {
			throw new ValidationException($this->l->t('Unknown employee.'));
		}
		$this->assertCountingType($typeId);
		$ent = $this->balanceService->ensureEntitlement($employeeUid, $year, $typeId);
		return $this->update($actorUid, $ent->getId(), $data);
	}

	/**
	 * Bulk-set the base entitlement for a whole group (or everyone) for a year,
	 * for a given counting leave type (§6.1).
	 *
	 * @return int number of employees affected
	 */
	public function bulkSet(int $year, int $typeId, float $baseDays, ?string $group): int {
		$this->assertCountingType($typeId);
		$count = 0;
		foreach ($this->targetUids($group) as $uid) {
			$ent = $this->balanceService->ensureEntitlement($uid, $year, $typeId);
			$ent->setBaseDays($baseDays);
			$ent->setUpdatedAt($this->clock->now());
			$this->entitlementMapper->update($ent);
			$count++;
		}
		$this->logger->info('Absence action: entitlement_bulk_set', [
			'app' => 'absence',
			'action' => 'entitlement_bulk_set',
			'year' => $year,
			'typeId' => $typeId,
			'baseDays' => $baseDays,
			'group' => $group,
			'affected' => $count,
		]);
		return $count;
	}

	/**
	 * Carry-over rollover from $fromYear into $fromYear + 1 (§6.2). Idempotent.
	 *
	 * @return int rows created/updated
	 */
	public function rollover(int $fromYear): int {
		$policy = $this->config->getCarryOverPolicy();
		$toYear = $fromYear + 1;
		$affected = 0;

		// Only roll over employees who actually had an entitlement last year, so we
		// never fabricate balances for users/types HR never granted (§6.1/§6.2).
		foreach ($this->entitlementMapper->findForYear($fromYear) as $prior) {
			$carry = $this->computeCarryOver($prior->getEmployeeUid(), $fromYear, $prior->getTypeId(), $policy);
			try {
				$next = $this->entitlementMapper->findFor($prior->getEmployeeUid(), $toYear, $prior->getTypeId());
			} catch (DoesNotExistException) {
				// The new year continues the prior year's base — never the global
				// default, which would silently override HR-set custom entitlements.
				$now = $this->clock->now();
				$next = new Entitlement();
				$next->setEmployeeUid($prior->getEmployeeUid());
				$next->setYear($toYear);
				$next->setTypeId($prior->getTypeId());
				$next->setBaseDays($prior->getBaseDays());
				$next->setCarryOverDays(0.0);
				$next->setManualAdjustment(0.0);
				$next->setCreatedAt($now);
				$next = $this->entitlementMapper->insert($next);
			}
			$next->setCarryOverDays($carry);
			$next->setUpdatedAt($this->clock->now());
			$this->entitlementMapper->update($next);
			$affected++;
		}
		$this->logger->info('Absence action: carryover_rollover', [
			'app' => 'absence',
			'action' => 'carryover_rollover',
			'fromYear' => $fromYear,
			'toYear' => $toYear,
			'policy' => $policy,
			'affected' => $affected,
		]);
		return $affected;
	}

	private function computeCarryOver(string $uid, int $year, int $typeId, string $policy): float {
		if ($policy === ConfigService::CARRYOVER_NONE) {
			return 0.0;
		}
		$remaining = 0.0;
		foreach ($this->balanceService->getBalance($uid, $year)['balances'] as $row) {
			if ($row['typeId'] === $typeId && $row['remaining'] !== null) {
				$remaining = max(0.0, (float)$row['remaining']);
			}
		}
		if ($policy === ConfigService::CARRYOVER_CAPPED) {
			return min($remaining, $this->config->getCarryOverCap());
		}
		return $remaining; // unlimited
	}

	/**
	 * Zero out carry-over that has passed its configured expiry date (§6.2).
	 *
	 * @return int rows zeroed
	 */
	public function expireCarryOver(int $year): int {
		$expiry = $this->config->getCarryOverExpiry(); // 'MM-DD' or ''
		if ($expiry === '') {
			return 0;
		}
		// company policy, run from a background job: the server's today
		$today = $this->clock->serverToday();
		if ($today < sprintf('%04d-%s', $year, $expiry)) {
			return 0;
		}
		$affected = 0;
		foreach ($this->entitlementMapper->findForYear($year) as $ent) {
			if ($ent->getCarryOverDays() > 0.0) {
				$ent->setCarryOverDays(0.0);
				$ent->setUpdatedAt($this->clock->now());
				$this->entitlementMapper->update($ent);
				$affected++;
			}
		}
		if ($affected > 0) {
			$this->logger->info('Absence action: carryover_expired', [
				'app' => 'absence',
				'action' => 'carryover_expired',
				'year' => $year,
				'affected' => $affected,
			]);
		}
		return $affected;
	}

	/**
	 * The chronological history of an entitlement, oldest first (§6.1).
	 *
	 * @return EntitlementEvent[]
	 */
	public function historyFor(int $entitlementId): array {
		return $this->eventMapper->findForEntitlement($entitlementId);
	}

	/**
	 * Record one event per figure this save actually moved.
	 *
	 * One row per figure, not per save: "+2 days for the wedding" is then a fact
	 * that reads on its own, instead of something a reader has to diff out of a
	 * blob. The note is attached to every figure the save touched, because it is
	 * the reason the whole save happened.
	 *
	 * Best-effort, like the request timeline: an unwritable history must not cost
	 * HR the adjustment they just made.
	 *
	 * @param array<string,float> $before figure => value before the save
	 * @return EntitlementEvent[] the events actually written
	 */
	private function recordChanges(string $actorUid, Entitlement $ent, array $before, string $note): array {
		$after = [
			EntitlementEvent::FIELD_BASE_DAYS => $ent->getBaseDays(),
			EntitlementEvent::FIELD_CARRY_OVER_DAYS => $ent->getCarryOverDays(),
			EntitlementEvent::FIELD_MANUAL_ADJUSTMENT => $ent->getManualAdjustment(),
		];
		$written = [];
		foreach ($after as $field => $newValue) {
			$oldValue = $before[$field];
			// Float equality is the wrong test for days entered as decimals.
			if (abs($newValue - $oldValue) < 0.001) {
				continue;
			}
			try {
				$event = new EntitlementEvent();
				$event->setEntitlementId((int)$ent->getId());
				$event->setEmployeeUid($ent->getEmployeeUid());
				$event->setActorUid($actorUid);
				$event->setField($field);
				$event->setOldValue($oldValue);
				$event->setNewValue($newValue);
				$event->setNote($note !== '' ? $note : null);
				$event->setCreatedAt($this->clock->now());
				$written[] = $this->eventMapper->insert($event);
			} catch (\Throwable $e) {
				$this->logger->warning('Absence: could not record entitlement history', ['exception' => $e]);
			}
		}
		return $written;
	}

	/**
	 * The changes as one short line, for the activity feed and the log.
	 *
	 * @param EntitlementEvent[] $changes
	 */
	private function summarise(array $changes): string {
		$labels = [
			EntitlementEvent::FIELD_BASE_DAYS => 'base',
			EntitlementEvent::FIELD_CARRY_OVER_DAYS => 'carry-over',
			EntitlementEvent::FIELD_MANUAL_ADJUSTMENT => 'adjustment',
		];
		$parts = [];
		foreach ($changes as $change) {
			$delta = $change->getNewValue() - $change->getOldValue();
			$parts[] = ($labels[$change->getField()] ?? $change->getField())
				. ' ' . ($delta > 0 ? '+' : '−') . $this->days(abs($delta));
		}
		return implode(', ', $parts);
	}

	/** A day count without a trailing `.0`. */
	private function days(float $value): string {
		return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
	}

	/**
	 * An entitlement is only meaningful for a type that counts against the balance,
	 * and only for a type that exists.
	 *
	 * The mapper throws DoesNotExistException, which is not an AbsenceException, so
	 * letting it out turns a stale type id — an HR form left open while somebody else
	 * removed the type — into "An unexpected error occurred" and a 500 in the log,
	 * rather than a 422 saying what was wrong.
	 *
	 * @throws ValidationException
	 */
	private function assertCountingType(int $typeId): void {
		try {
			$type = $this->leaveTypeMapper->find($typeId);
		} catch (DoesNotExistException) {
			throw new ValidationException($this->l->t('Unknown leave type.'));
		}
		if (!$type->getCountsAgainstBalance()) {
			throw new ValidationException($this->l->t('Entitlements only apply to leave types that count against the balance.'));
		}
	}

	/**
	 * @return string[]
	 */
	private function targetUids(?string $group): array {
		return $this->employees->listInGroup($group);
	}
}
