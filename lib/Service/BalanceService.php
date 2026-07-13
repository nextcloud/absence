<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\Entitlement;
use OCA\Absence\Db\EntitlementMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Computes leave balances from requests + entitlements (spec §3.4, §6). Balances
 * are always computed, never stored.
 */
class BalanceService {
	public function __construct(
		private LeaveRequestMapper $requestMapper,
		private EntitlementMapper $entitlementMapper,
		private LeaveTypeMapper $leaveTypeMapper,
		private ConfigService $config,
	) {
	}

	public function currentYear(): int {
		return (int)date('Y');
	}

	/**
	 * Sum working days per (typeId, year, bucket) for an employee.
	 *
	 * @return array<int,array<int,array{used:float,pending:float}>> [typeId][year] => buckets
	 */
	private function computeUsage(string $employeeUid): array {
		$usage = [];
		foreach ($this->requestMapper->findAllForEmployee($employeeUid) as $request) {
			$status = $request->getStatus();
			$isUsed = in_array($status, LeaveRequest::USED_STATUSES, true);
			$isPending = in_array($status, LeaveRequest::PENDING_STATUSES, true);
			if (!$isUsed && !$isPending) {
				continue;
			}
			$typeId = $request->getTypeId();
			// Working days are entered manually; attribute them to the year the leave starts.
			$year = (int)substr($request->getStartDate(), 0, 4);
			$usage[$typeId][$year] ??= ['used' => 0.0, 'pending' => 0.0];
			$usage[$typeId][$year][$isUsed ? 'used' : 'pending'] += $request->getWorkingDays();
		}
		return $usage;
	}

	/**
	 * Balance rows for an employee. When $year is null, returns the current year
	 * plus any other year that has entitlements or activity.
	 *
	 * @return array{employeeUid:string,balances:list<array<string,mixed>>}
	 */
	public function getBalance(string $employeeUid, ?int $year = null): array {
		$usage = $this->computeUsage($employeeUid);
		$types = [];
		foreach ($this->leaveTypeMapper->findAll() as $type) {
			$types[$type->getId()] = $type;
		}

		// Determine which years to report.
		$years = [];
		if ($year !== null) {
			$years[$year] = true;
		} else {
			$years[$this->currentYear()] = true;
			foreach ($this->entitlementMapper->findForEmployee($employeeUid) as $ent) {
				$years[$ent->getYear()] = true;
			}
			foreach ($usage as $perYear) {
				foreach (array_keys($perYear) as $y) {
					$years[$y] = true;
				}
			}
		}

		$rows = [];
		foreach (array_keys($years) as $reportYear) {
			foreach ($types as $typeId => $type) {
				$used = $usage[$typeId][$reportYear]['used'] ?? 0.0;
				$pending = $usage[$typeId][$reportYear]['pending'] ?? 0.0;
				// Only include non-counting types when they have activity.
				if (!$type->getCountsAgainstBalance() && $used === 0.0 && $pending === 0.0) {
					continue;
				}
				$rows[] = $this->buildRow($employeeUid, $reportYear, $type, $used, $pending);
			}
		}
		// Newest year first, then sort_order.
		usort($rows, static function (array $a, array $b): int {
			return [$b['year'], $a['sortOrder']] <=> [$a['year'], $b['sortOrder']];
		});

		return [
			'employeeUid' => $employeeUid,
			'balances' => $rows,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function buildRow(string $employeeUid, int $year, LeaveType $type, float $used, float $pending): array {
		$entitlement = null;
		$base = 0.0;
		$carry = 0.0;
		$adjust = 0.0;
		$entitlementId = null;
		if ($type->getCountsAgainstBalance()) {
			try {
				$ent = $this->entitlementMapper->findFor($employeeUid, $year, $type->getId());
				$base = $ent->getBaseDays();
				$carry = $ent->getCarryOverDays();
				$adjust = $ent->getManualAdjustment();
				$entitlement = $ent->getEntitlement();
				$entitlementId = $ent->getId();
			} catch (DoesNotExistException) {
				// No row yet: only the primary annual type inherits the configured
				// default allotment; other counting types start at zero until HR
				// grants an entitlement (avoids fabricating balances, §6.1).
				$base = $type->getKey() === 'annual' ? $this->config->getDefaultEntitlement() : 0.0;
				$entitlement = $base;
			}
		}

		$remaining = $entitlement === null ? null : round($entitlement - $used, 1);
		$available = $entitlement === null ? null : round($entitlement - $used - $pending, 1);

		return [
			'year' => $year,
			'typeId' => $type->getId(),
			'typeKey' => $type->getKey(),
			'typeLabel' => $type->getLabel(),
			'typeColor' => $type->getColor(),
			'typeIcon' => $type->getIcon(),
			'sortOrder' => $type->getSortOrder(),
			'countsAgainstBalance' => $type->getCountsAgainstBalance(),
			'entitlementId' => $entitlementId,
			'baseDays' => $base,
			'carryOverDays' => $carry,
			'manualAdjustment' => $adjust,
			'entitlement' => $entitlement,
			'used' => round($used, 1),
			'pending' => round($pending, 1),
			'remaining' => $remaining,
			'available' => $available,
		];
	}

	/**
	 * The available balance for a single counting type in a year — used by the
	 * create flow to warn about (not block) negative balances.
	 */
	public function availableFor(string $employeeUid, int $typeId, int $year): ?float {
		$type = null;
		foreach ($this->getBalance($employeeUid, $year)['balances'] as $row) {
			if ($row['typeId'] === $typeId) {
				return $row['available'];
			}
		}
		return null;
	}

	/**
	 * Ensure an entitlement row exists for (employee, year, type); creates one from
	 * the configured default when missing. Returns the row.
	 */
	public function ensureEntitlement(string $employeeUid, int $year, int $typeId): Entitlement {
		try {
			return $this->entitlementMapper->findFor($employeeUid, $year, $typeId);
		} catch (DoesNotExistException) {
			$now = new \DateTime();
			$ent = new Entitlement();
			$ent->setEmployeeUid($employeeUid);
			$ent->setYear($year);
			$ent->setTypeId($typeId);
			$ent->setBaseDays($this->config->getDefaultEntitlement());
			$ent->setCarryOverDays(0.0);
			$ent->setManualAdjustment(0.0);
			$ent->setCreatedAt($now);
			$ent->setUpdatedAt($now);
			return $this->entitlementMapper->insert($ent);
		}
	}
}
