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
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\NotFoundException;
use OCA\Absence\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/**
 * Entitlement management for HR and the year-rollover / carry-over logic (§6).
 */
class EntitlementService {
	public function __construct(
		private EntitlementMapper $entitlementMapper,
		private EntitlementEventMapper $eventMapper,
		private LeaveRequestMapper $requestMapper,
		private LeaveTypeMapper $leaveTypeMapper,
		private BalanceService $balanceService,
		private ConfigService $config,
		private ClockService $clock,
		private ActivityPublisher $activity,
		private EmployeeDirectory $employees,
		private EntitlementValidator $validator,
		private IDBConnection $db,
		private IL10N $l,
		private LoggerInterface $logger,
		private IEventDispatcher $dispatcher,
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
	public function update(?string $actorUid, int $id, array $data): Entitlement {
		if (array_key_exists('adjustmentDelta', $data) && array_key_exists('manualAdjustment', $data)) {
			throw new ValidationException($this->l->t('Send either an adjustment to apply or an absolute adjustment, not both.'));
		}
		$this->validator->assertFigures($data);
		$note = trim((string)($data['adjustmentNote'] ?? ''));
		$delta = (float)($data['adjustmentDelta'] ?? 0.0);
		if (abs($delta) <= 0.001) {
			$delta = 0.0;
		} elseif ($note === '') {
			throw new ValidationException($this->l->t('A note is required when adjusting an entitlement.'));
		}

		// One transaction: the figures and their history are saved together or not at all.
		$this->db->beginTransaction();
		try {
			if ($delta !== 0.0) {
				// A correction on top of whatever corrections came before, so "+2" and a
				// later "-2" cancel. Added by the database, not to a value read earlier:
				// a correction somebody else saves at the same moment is kept too.
				$this->entitlementMapper->addToManualAdjustment($id, $delta);
			}
			try {
				$ent = $this->entitlementMapper->find($id);
			} catch (DoesNotExistException) {
				throw new NotFoundException($this->l->t('Entitlement not found'));
			}
			// Read before any setter runs: these are what the history reports moving from.
			$before = [
				EntitlementEvent::FIELD_BASE_DAYS => $ent->getBaseDays(),
				EntitlementEvent::FIELD_CARRY_OVER_DAYS => $ent->getCarryOverDays(),
				EntitlementEvent::FIELD_MANUAL_ADJUSTMENT => round($ent->getManualAdjustment() - $delta, 1),
			];

			if (array_key_exists('baseDays', $data)) {
				$ent->setBaseDays((float)$data['baseDays']);
			}
			if (array_key_exists('carryOverDays', $data)) {
				$ent->setCarryOverDays((float)$data['carryOverDays']);
			}
			if ($delta !== 0.0) {
				$ent->setAdjustmentNote($data['adjustmentNote'] ?? $ent->getAdjustmentNote());
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
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->activity->publish(ActivityPublisher::SUBJECT_BALANCE_ADJUSTED, [
			'employee' => $ent->getEmployeeUid(),
			'year' => $ent->getYear(),
			// Carried so the activity entry can say what happened rather than only
			// that something did. Empty when a save changed no figure.
			'summary' => $this->summarise($changes),
			'note' => $note,
		], $actorUid === null ? [$ent->getEmployeeUid()] : [$ent->getEmployeeUid(), $actorUid]);
		$this->logger->info('Absence action: entitlement_updated', [
			'app' => 'absence',
			'action' => 'entitlement_updated',
			'actor' => $actorUid,
			'entitlementId' => $ent->getId(),
			'employee' => $ent->getEmployeeUid(),
			'year' => $ent->getYear(),
		]);
		$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(
			'Absence entitlement %s updated by %s', [(string)$ent->getId(), $actorUid],
		));
		return $ent;
	}

	/**
	 * Create (or fetch) the entitlement row for a single employee and apply the
	 * given values (HR). Unlike bulkSet() this never touches other employees.
	 *
	 * @param array{baseDays?:float,carryOverDays?:float,manualAdjustment?:float,adjustmentNote?:string} $data
	 */
	public function setForEmployee(?string $actorUid, string $employeeUid, int $year, int $typeId, array $data): Entitlement {
		// Also rejects guests: they have no entitlement to set (§2.2).
		if (!$this->employees->isEmployee($employeeUid)) {
			throw new ValidationException($this->l->t('Unknown employee.'));
		}
		$this->assertCountingType($typeId);
		$this->validator->assertYear($year);
		$this->validator->assertFigures($data);
		$ent = $this->balanceService->ensureEntitlement($employeeUid, $year, $typeId);
		return $this->update($actorUid, $ent->getId(), $data);
	}

	/**
	 * Bulk-set the base entitlement for a whole group (or everyone) for a year,
	 * for a given counting leave type (§6.1). All or nothing, and recorded in each
	 * employee's entitlement history like a single edit.
	 *
	 * @return int number of employees affected
	 */
	public function bulkSet(string $actorUid, int $year, int $typeId, float $baseDays, ?string $group): int {
		$this->validator->assertYear($year);
		$this->validator->assertFigures(['baseDays' => $baseDays]);
		$this->assertCountingType($typeId);
		$count = 0;
		$this->db->beginTransaction();
		try {
			foreach ($this->targetUids($group) as $uid) {
				$ent = $this->balanceService->ensureEntitlement($uid, $year, $typeId);
				$before = [
					EntitlementEvent::FIELD_BASE_DAYS => $ent->getBaseDays(),
					EntitlementEvent::FIELD_CARRY_OVER_DAYS => $ent->getCarryOverDays(),
					EntitlementEvent::FIELD_MANUAL_ADJUSTMENT => $ent->getManualAdjustment(),
				];
				$ent->setBaseDays($baseDays);
				$ent->setUpdatedAt($this->clock->now());
				$ent = $this->entitlementMapper->update($ent);
				$this->recordChanges($actorUid, $ent, $before, '');
				$count++;
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->logger->info('Absence action: entitlement_bulk_set', [
			'app' => 'absence',
			'action' => 'entitlement_bulk_set',
			'actor' => $actorUid,
			'year' => $year,
			'typeId' => $typeId,
			'baseDays' => $baseDays,
			'group' => $group,
			'affected' => $count,
		]);
		$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(
			'Absence entitlements of leave type %s for %s set for %s people', [(string)$typeId, (string)$year, (string)$count],
		));
		return $count;
	}

	/**
	 * The employees whose annual entitlement of the running year has $days as
	 * base: the ones a new default could be handed on to (§6.1).
	 *
	 * @return Entitlement[]
	 */
	private function onDefault(float $days): array {
		$annualTypeId = $this->annualTypeId();
		if ($annualTypeId === null) {
			return [];
		}
		$employees = array_fill_keys($this->employees->listAll(), true);
		return array_values(array_filter(
			$this->entitlementMapper->findForYear($this->clock->serverYear()),
			static fn (Entitlement $ent): bool => $ent->getTypeId() === $annualTypeId
				&& isset($employees[$ent->getEmployeeUid()])
				&& abs($ent->getBaseDays() - $days) < 0.001,
		));
	}

	/**
	 * @return array{year: int, count: int} how many employees have $days as base this year
	 */
	public function countOnDefault(float $days): array {
		return ['year' => $this->clock->serverYear(), 'count' => count($this->onDefault($days))];
	}

	/**
	 * Hands a changed default on to the employees who had the old one as their
	 * base this year (§6.1). Somebody with a base of their own keeps it. All or
	 * nothing, and recorded in each entitlement history.
	 *
	 * @return int the entitlements changed
	 * @throws ValidationException
	 */
	public function applyDefault(string $actorUid, float $from, float $to): int {
		$this->validator->assertFigures(['baseDays' => $to]);
		$note = $this->l->t('Default entitlement changed from %1$s to %2$s days', [$this->days($from), $this->days($to)]);
		$rows = $this->onDefault($from);
		$this->db->beginTransaction();
		try {
			foreach ($rows as $ent) {
				$before = [
					EntitlementEvent::FIELD_BASE_DAYS => $ent->getBaseDays(),
					EntitlementEvent::FIELD_CARRY_OVER_DAYS => $ent->getCarryOverDays(),
					EntitlementEvent::FIELD_MANUAL_ADJUSTMENT => $ent->getManualAdjustment(),
				];
				$ent->setBaseDays($to);
				$ent->setUpdatedAt($this->clock->now());
				$this->recordChanges($actorUid, $this->entitlementMapper->update($ent), $before, $note);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->logger->info('Absence action: entitlement_default_applied', [
			'app' => 'absence',
			'action' => 'entitlement_default_applied',
			'actor' => $actorUid,
			'from' => $from,
			'to' => $to,
			'affected' => count($rows),
		]);
		$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(
			'Absence default entitlement change from %s to %s applied to %s people', [$this->days($from), $this->days($to), (string)count($rows)],
		));
		return count($rows);
	}

	/**
	 * Writes down the default as it stands for every past year an employee has
	 * no annual entitlement for, so that a new default does not rewrite the
	 * past (§6.1). Call it before the default changes. No balance moves, so
	 * nothing goes to the history.
	 *
	 * @return int the entitlements created
	 */
	public function freezePastDefault(): int {
		$annualTypeId = $this->annualTypeId();
		if ($annualTypeId === null) {
			return 0;
		}
		// Before the first entitlement and the first leave there is no past to keep
		$firstLeave = $this->requestMapper->firstStartDate();
		$first = min(
			$this->entitlementMapper->firstYear() ?? PHP_INT_MAX,
			$firstLeave === null ? PHP_INT_MAX : (int)substr($firstLeave, 0, 4),
		);
		$employees = $this->employees->listAll();
		$created = 0;
		for ($year = $first; $year < $this->clock->serverYear(); $year++) {
			$has = [];
			foreach ($this->entitlementMapper->findForYear($year) as $ent) {
				if ($ent->getTypeId() === $annualTypeId) {
					$has[$ent->getEmployeeUid()] = true;
				}
			}
			foreach ($employees as $uid) {
				if (!isset($has[$uid])) {
					$this->balanceService->ensureEntitlement($uid, $year, $annualTypeId);
					$created++;
				}
			}
		}
		return $created;
	}

	/**
	 * Carry-over rollover from $fromYear into $fromYear + 1 (§6.2). Recomputed from
	 * $fromYear on every run, so running it again is safe.
	 *
	 * @return array{affected:int,skipped:int,failed:int} rows created or updated, rows
	 *                                                    left alone because their carry-over was set by hand, rows that failed
	 */
	public function rollover(int $fromYear): array {
		$policy = $this->config->getCarryOverPolicy();
		$toYear = $fromYear + 1;

		// [uid][typeId] => the base the new year continues: the prior year's, never
		// the global default, which would override entitlements HR customised.
		$targets = [];
		foreach ($this->entitlementMapper->findForYear($fromYear) as $prior) {
			$targets[$prior->getEmployeeUid()][$prior->getTypeId()] = $prior->getBaseDays();
		}
		// Employees without a row live on the configured default, for the annual
		// type only (§6.1): they carry over like everyone else.
		$annualTypeId = $this->annualTypeId();
		if ($annualTypeId !== null) {
			$default = $this->config->getDefaultEntitlement();
			foreach ($this->employees->listAll() as $uid) {
				$targets[$uid][$annualTypeId] ??= $default;
			}
		}

		$balances = $this->balanceService->getBalancesForEmployees(array_map('strval', array_keys($targets)), $fromYear);
		$existing = [];
		foreach ($this->entitlementMapper->findForYear($toYear) as $next) {
			$existing[$next->getEmployeeUid()][$next->getTypeId()] = $next;
		}
		$setByHand = $this->eventMapper->findIdsWithCarryOverNotSetBy($toYear, EntitlementEvent::ACTOR_SYSTEM);

		$result = ['affected' => 0, 'skipped' => 0, 'failed' => 0];
		foreach ($targets as $uid => $perType) {
			$uid = (string)$uid;
			foreach ($perType as $typeId => $baseDays) {
				// One employee whose rollover throws must not abort the others: log
				// the row, carry on, and report it so the caller can retry the year.
				try {
					$next = $existing[$uid][$typeId] ?? null;
					if ($next !== null && isset($setByHand[(int)$next->getId()])) {
						$result['skipped']++;
						continue;
					}
					$carry = $this->carryOverFrom($balances[$uid] ?? [], $typeId, $policy);
					$now = $this->clock->now();
					if ($next === null) {
						$before = 0.0;
						$next = new Entitlement();
						$next->setEmployeeUid($uid);
						$next->setYear($toYear);
						$next->setTypeId($typeId);
						$next->setBaseDays($baseDays);
						$next->setCarryOverDays($carry);
						$next->setManualAdjustment(0.0);
						$next->setCreatedAt($now);
						$next->setUpdatedAt($now);
						$next = $this->entitlementMapper->insert($next);
					} else {
						$before = $next->getCarryOverDays();
						$next->setCarryOverDays($carry);
						$next->setUpdatedAt($now);
						$next = $this->entitlementMapper->update($next);
					}
					$this->recordChanges(EntitlementEvent::ACTOR_SYSTEM, $next, [
						EntitlementEvent::FIELD_BASE_DAYS => $next->getBaseDays(),
						EntitlementEvent::FIELD_CARRY_OVER_DAYS => $before,
						EntitlementEvent::FIELD_MANUAL_ADJUSTMENT => $next->getManualAdjustment(),
					], 'Year rollover');
					$result['affected']++;
				} catch (\Throwable $e) {
					$result['failed']++;
					$this->logger->error('Absence: failed to roll over entitlement', [
						'app' => 'absence',
						'employee' => $uid,
						'typeId' => $typeId,
						'fromYear' => $fromYear,
						'exception' => $e,
					]);
				}
			}
		}
		$this->logger->info('Absence action: carryover_rollover', [
			'app' => 'absence',
			'action' => 'carryover_rollover',
			'fromYear' => $fromYear,
			'toYear' => $toYear,
			'policy' => $policy,
		] + $result);
		$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(
			'Absence carry-over from %s to %s applied to %s entitlements', [(string)$fromYear, (string)$toYear, (string)$result['affected']],
		));
		return $result;
	}

	/**
	 * @param list<array<string,mixed>> $balanceRows one employee's balance rows for the year
	 */
	private function carryOverFrom(array $balanceRows, int $typeId, string $policy): float {
		if ($policy === ConfigService::CARRYOVER_NONE) {
			return 0.0;
		}
		$remaining = 0.0;
		foreach ($balanceRows as $row) {
			if ($row['typeId'] === $typeId && $row['remaining'] !== null) {
				$remaining = max(0.0, (float)$row['remaining']);
			}
		}
		if ($policy === ConfigService::CARRYOVER_CAPPED) {
			return min($remaining, $this->config->getCarryOverCap());
		}
		return $remaining; // unlimited
	}

	private function annualTypeId(): ?int {
		foreach ($this->leaveTypeMapper->findAll() as $type) {
			if ($type->getKey() === 'annual' && $type->getCountsAgainstBalance()) {
				return (int)$type->getId();
			}
		}
		return null;
	}

	/**
	 * Expire the carry-over that was not used by the configured expiry date (§6.2):
	 * what remains is the part covered by leave starting on or before that date.
	 * A later run finds nothing left to expire.
	 *
	 * @return int rows reduced
	 */
	public function expireCarryOver(int $year): int {
		$expiryDate = $this->balanceService->carryOverExpiryDate($year);
		if ($expiryDate === null) {
			return 0;
		}
		// company policy, run from a background job: the server's today
		if ($this->clock->serverToday() < $expiryDate) {
			return 0;
		}
		$used = null;
		$affected = 0;
		foreach ($this->entitlementMapper->findForYear($year) as $ent) {
			$carry = $ent->getCarryOverDays();
			if ($carry <= 0.0) {
				continue;
			}
			$used ??= $this->balanceService->usedDaysByCarryOverExpiry($year, $expiryDate);
			$kept = $this->balanceService->carryOverKept($carry, $used[$ent->getEmployeeUid()][$ent->getTypeId()] ?? 0.0);
			if ($carry - $kept < 0.001) {
				continue;
			}
			$ent->setCarryOverDays($kept);
			$ent->setUpdatedAt($this->clock->now());
			$ent = $this->entitlementMapper->update($ent);
			$this->recordChanges(EntitlementEvent::ACTOR_SYSTEM, $ent, [
				EntitlementEvent::FIELD_BASE_DAYS => $ent->getBaseDays(),
				EntitlementEvent::FIELD_CARRY_OVER_DAYS => $carry,
				EntitlementEvent::FIELD_MANUAL_ADJUSTMENT => $ent->getManualAdjustment(),
			], 'Carry-over expired');
			$affected++;
		}
		if ($affected > 0) {
			$this->logger->info('Absence action: carryover_expired', [
				'app' => 'absence',
				'action' => 'carryover_expired',
				'year' => $year,
				'affected' => $affected,
			]);
			$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(
				'Absence carry-over of %s expired on %s entitlements', [(string)$year, (string)$affected],
			));
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
	private function recordChanges(?string $actorUid, Entitlement $ent, array $before, string $note): array {
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
