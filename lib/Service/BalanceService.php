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
		private ClockService $clock,
	) {
	}

	public function currentYear(): int {
		return $this->clock->userYear();
	}

	/**
	 * Sum working days per (typeId, year, bucket) for an employee.
	 *
	 * @return array<int,array<int,array{used:float,pending:float}>> [typeId][year] => buckets
	 */
	private function computeUsage(string $employeeUid): array {
		return $this->usageFromRequests($this->requestMapper->findAllForEmployee($employeeUid));
	}

	/**
	 * The usage half of {@see computeUsage()}, over requests already in memory, so
	 * a batch report can load every employee's requests in one query.
	 *
	 * @param LeaveRequest[] $requests
	 * @return array<int,array<int,array{used:float,pending:float}>> [typeId][year] => buckets
	 */
	private function usageFromRequests(array $requests): array {
		$byId = [];
		foreach ($requests as $request) {
			$byId[$request->getId()] = $request;
		}
		$usage = [];
		foreach ($requests as $request) {
			$status = $request->getStatus();
			$isUsed = in_array($status, LeaveRequest::USED_STATUSES, true);
			$isPending = in_array($status, LeaveRequest::PENDING_STATUSES, true);
			if (!$isUsed && !$isPending) {
				continue;
			}
			$typeId = $request->getTypeId();
			// Working days are entered manually; attribute them to the year the leave starts.
			$year = (int)substr($request->getStartDate(), 0, 4);
			$days = $request->getWorkingDays();
			// A pending edit of approved leave (§5.3) supersedes a request that is
			// still counted itself, so only the extra days it asks for are genuinely
			// pending — otherwise the same leave is deducted twice until the edit
			// is decided. Netting only applies within the same type and year.
			if ($isPending && $request->getSupersedesId() !== null) {
				$original = $byId[$request->getSupersedesId()] ?? null;
				if ($original !== null
					&& in_array($original->getStatus(), LeaveRequest::ACTIVE_STATUSES, true)
					&& $original->getTypeId() === $typeId
					&& (int)substr($original->getStartDate(), 0, 4) === $year) {
					$days = max(0.0, $days - $original->getWorkingDays());
				}
			}
			$usage[$typeId][$year] ??= ['used' => 0.0, 'pending' => 0.0];
			$usage[$typeId][$year][$isUsed ? 'used' : 'pending'] += $days;
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
		$types = $this->typesById();

		// Entitlements for this employee: restricted to the reported year when there
		// is one, otherwise all of them, since they also decide which years to show.
		$entitlements = [];
		foreach ($this->entitlementMapper->findForEmployee($employeeUid, $year) as $ent) {
			$entitlements[$ent->getYear()][$ent->getTypeId()] = $ent;
		}

		return [
			'employeeUid' => $employeeUid,
			'balances' => $this->assembleRows($employeeUid, $year, $usage, $types, $entitlements),
		];
	}

	/**
	 * Balances for many employees in one year, in a fixed number of queries
	 * regardless of headcount.
	 *
	 * The obvious loop over {@see getBalance()} costs one request query, one leave
	 * type query and one entitlement query *per leave type* for every employee —
	 * several thousand queries for a mid-sized company, which is what made the HR
	 * balances report unusable at scale.
	 *
	 * @param string[] $employeeUids
	 * @return array<string,list<array<string,mixed>>> balance rows keyed by employee uid
	 */
	public function getBalancesForEmployees(array $employeeUids, int $year): array {
		if ($employeeUids === []) {
			return [];
		}
		$types = $this->typesById();
		$requestsByEmployee = $this->requestMapper->findAllForEmployees($employeeUids);

		$entitlementsByEmployee = [];
		foreach ($this->entitlementMapper->findForYear($year) as $ent) {
			$entitlementsByEmployee[$ent->getEmployeeUid()][$year][$ent->getTypeId()] = $ent;
		}

		$result = [];
		foreach ($employeeUids as $uid) {
			$usage = $this->usageFromRequests($requestsByEmployee[$uid] ?? []);
			$result[$uid] = $this->assembleRows($uid, $year, $usage, $types, $entitlementsByEmployee[$uid] ?? []);
		}
		return $result;
	}

	/**
	 * @return array<int,LeaveType>
	 */
	private function typesById(): array {
		$types = [];
		foreach ($this->leaveTypeMapper->findAll() as $type) {
			$types[$type->getId()] = $type;
		}
		return $types;
	}

	/**
	 * Turn precomputed usage, types and entitlements into balance rows. Shared by
	 * the single-employee and batch paths so both produce identical output.
	 *
	 * @param array<int,array<int,array{used:float,pending:float}>> $usage
	 * @param array<int,LeaveType> $types
	 * @param array<int,array<int,Entitlement>> $entitlements [year][typeId]
	 * @return list<array<string,mixed>>
	 */
	private function assembleRows(string $employeeUid, ?int $year, array $usage, array $types, array $entitlements): array {
		// Determine which years to report.
		$years = [];
		if ($year !== null) {
			$years[$year] = true;
		} else {
			$years[$this->currentYear()] = true;
			foreach (array_keys($entitlements) as $entYear) {
				$years[$entYear] = true;
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
				$rows[] = $this->buildRow($employeeUid, $reportYear, $type, $used, $pending, $entitlements[$reportYear][$typeId] ?? null);
			}
		}
		// Newest year first, then sort_order.
		usort($rows, static function (array $a, array $b): int {
			return [$b['year'], $a['sortOrder']] <=> [$a['year'], $b['sortOrder']];
		});
		return $rows;
	}

	/**
	 * @return array<string,mixed>
	 */
	/**
	 * @param ?Entitlement $ent the stored entitlement, or null when none exists.
	 *                          Passed in rather than looked up here so a batch
	 *                          report can resolve every employee's rows from one
	 *                          preloaded index instead of a query per row.
	 */
	private function buildRow(string $employeeUid, int $year, LeaveType $type, float $used, float $pending, ?Entitlement $ent): array {
		$entitlement = null;
		$base = 0.0;
		$carry = 0.0;
		$adjust = 0.0;
		$entitlementId = null;
		if ($type->getCountsAgainstBalance()) {
			if ($ent !== null) {
				$base = $ent->getBaseDays();
				$carry = $ent->getCarryOverDays();
				$adjust = $ent->getManualAdjustment();
				$entitlement = $ent->getEntitlement();
				$entitlementId = $ent->getId();
			} else {
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
	 * Ensure an entitlement row exists for (employee, year, type); creates one from
	 * the configured default when missing. Returns the row.
	 */
	public function ensureEntitlement(string $employeeUid, int $year, int $typeId): Entitlement {
		try {
			return $this->entitlementMapper->findFor($employeeUid, $year, $typeId);
		} catch (DoesNotExistException) {
			// Mirror buildRow(): only the primary annual type inherits the configured
			// default; other counting types start at zero, so creating the row never
			// changes the computed balance (§6.1).
			$type = $this->leaveTypeMapper->find($typeId);
			$now = $this->clock->now();
			$ent = new Entitlement();
			$ent->setEmployeeUid($employeeUid);
			$ent->setYear($year);
			$ent->setTypeId($typeId);
			$ent->setBaseDays($type->getKey() === 'annual' ? $this->config->getDefaultEntitlement() : 0.0);
			$ent->setCarryOverDays(0.0);
			$ent->setManualAdjustment(0.0);
			$ent->setCreatedAt($now);
			$ent->setUpdatedAt($now);
			return $this->entitlementMapper->insert($ent);
		}
	}
}
