<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\Entitlement;
use OCA\Absence\Db\EntitlementMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\NotFoundException;
use OCA\Absence\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Entitlement management for HR and the year-rollover / carry-over logic (§6).
 */
class EntitlementService {
	public function __construct(
		private EntitlementMapper $entitlementMapper,
		private LeaveTypeMapper $leaveTypeMapper,
		private BalanceService $balanceService,
		private ConfigService $config,
		private ActivityPublisher $activity,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
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
	 * @param array{baseDays?:float,carryOverDays?:float,manualAdjustment?:float,adjustmentNote?:string} $data
	 */
	public function update(string $actorUid, int $id, array $data): Entitlement {
		try {
			$ent = $this->entitlementMapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Entitlement not found');
		}
		if (array_key_exists('baseDays', $data)) {
			$ent->setBaseDays((float)$data['baseDays']);
		}
		if (array_key_exists('carryOverDays', $data)) {
			$ent->setCarryOverDays((float)$data['carryOverDays']);
		}
		if (array_key_exists('manualAdjustment', $data)) {
			$adjustment = (float)$data['manualAdjustment'];
			if ($adjustment !== $ent->getManualAdjustment() && trim((string)($data['adjustmentNote'] ?? '')) === '') {
				throw new ValidationException('A note is required when adjusting an entitlement.');
			}
			$ent->setManualAdjustment($adjustment);
			$ent->setAdjustmentNote($data['adjustmentNote'] ?? $ent->getAdjustmentNote());
		}
		$ent->setUpdatedAt(new \DateTime());
		$ent = $this->entitlementMapper->update($ent);

		$this->activity->publish(ActivityPublisher::SUBJECT_BALANCE_ADJUSTED, [
			'employee' => $ent->getEmployeeUid(),
			'year' => $ent->getYear(),
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
		if ($this->userManager->get($employeeUid) === null) {
			throw new ValidationException('Unknown employee.');
		}
		$type = $this->leaveTypeMapper->find($typeId);
		if (!$type->getCountsAgainstBalance()) {
			throw new ValidationException('Entitlements only apply to leave types that count against the balance.');
		}
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
		$type = $this->leaveTypeMapper->find($typeId);
		if (!$type->getCountsAgainstBalance()) {
			throw new ValidationException('Entitlements only apply to leave types that count against the balance.');
		}
		$count = 0;
		foreach ($this->targetUids($group) as $uid) {
			$ent = $this->balanceService->ensureEntitlement($uid, $year, $typeId);
			$ent->setBaseDays($baseDays);
			$ent->setUpdatedAt(new \DateTime());
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
				$now = new \DateTime();
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
			$next->setUpdatedAt(new \DateTime());
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
		$today = date('Y-m-d');
		if ($today < sprintf('%04d-%s', $year, $expiry)) {
			return 0;
		}
		$affected = 0;
		foreach ($this->entitlementMapper->findForYear($year) as $ent) {
			if ($ent->getCarryOverDays() > 0.0) {
				$ent->setCarryOverDays(0.0);
				$ent->setUpdatedAt(new \DateTime());
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
	 * @return string[]
	 */
	private function targetUids(?string $group): array {
		if ($group !== null && $group !== '') {
			$g = $this->groupManager->get($group);
			if ($g === null) {
				return [];
			}
			return array_map(static fn ($u) => $u->getUID(), $g->getUsers());
		}
		$uids = [];
		$this->userManager->callForAllUsers(static function ($user) use (&$uids): void {
			$uids[] = $user->getUID();
		});
		return $uids;
	}
}
