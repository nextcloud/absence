<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\ValidationException;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * CSV import of entitlements (§6.3) — the onboarding path from the spreadsheet
 * every company migrates away from.
 *
 * Expected columns (header row required, order free, extras ignored):
 *
 *   user            uid or e-mail address
 *   base_days       base allowance (optional — leave empty to keep)
 *   carry_over_days carry-over (optional)
 *   adjustment      manual adjustment, absolute (optional)
 *   note            reason recorded with the change (optional)
 *   type            leave-type key, defaults to 'annual' (optional)
 *   year            defaults to the year given at import time (optional)
 *
 * Every change goes through {@see EntitlementService::setForEmployee()}, so
 * the entitlement history records the import like any HR edit — nothing is
 * written behind the audit trail's back. The import is all-or-nothing per
 * file: any invalid row aborts before anything is written, because "half the
 * company imported" is the worst possible outcome of a typo.
 */
class ImportService {
	public function __construct(
		private EntitlementService $entitlements,
		private LeaveTypeMapper $leaveTypeMapper,
		private EmployeeDirectory $employees,
		private IUserManager $userManager,
		private IDBConnection $db,
	) {
	}

	/**
	 * Parse and validate the whole file, resolving users and types.
	 *
	 * @return list<array{uid:string,typeId:int,year:int,data:array<string,mixed>,line:int}>
	 * @throws ValidationException listing every broken line
	 */
	public function plan(string $csv, int $defaultYear): array {
		$rows = $this->parseCsv($csv);
		$typesByKey = [];
		foreach ($this->leaveTypeMapper->findAll() as $type) {
			$typesByKey[$type->getKey()] = $type;
		}

		$plan = [];
		$errors = [];
		foreach ($rows as $i => $row) {
			$line = $i + 2; // 1-based, after the header
			$uid = $this->resolveUser(trim($row['user'] ?? ''));
			if ($uid === null) {
				$errors[] = "line $line: unknown user '" . trim($row['user'] ?? '') . "'";
				continue;
			}
			if (!$this->employees->isEmployee($uid)) {
				$errors[] = "line $line: '$uid' is not an employee";
				continue;
			}
			$typeKey = trim($row['type'] ?? '') ?: 'annual';
			$type = $typesByKey[$typeKey] ?? null;
			if ($type === null || !$type->getCountsAgainstBalance()) {
				$errors[] = "line $line: unknown or non-counting leave type '$typeKey'";
				continue;
			}
			$year = trim($row['year'] ?? '') !== '' ? (int)$row['year'] : $defaultYear;
			if ($year < 2000 || $year > 2100) {
				$errors[] = "line $line: implausible year '$year'";
				continue;
			}

			$data = [];
			foreach (['base_days' => 'baseDays', 'carry_over_days' => 'carryOverDays', 'adjustment' => 'manualAdjustment'] as $col => $key) {
				$value = trim($row[$col] ?? '');
				if ($value === '') {
					continue;
				}
				if (!is_numeric($value)) {
					$errors[] = "line $line: '$col' is not a number ('$value')";
					continue 2;
				}
				$data[$key] = (float)$value;
			}
			if ($data === []) {
				$errors[] = "line $line: nothing to import (no base_days, carry_over_days or adjustment)";
				continue;
			}
			$note = trim($row['note'] ?? '');
			$data['adjustmentNote'] = $note !== '' ? $note : 'Imported from CSV';

			$plan[] = ['uid' => $uid, 'typeId' => (int)$type->getId(), 'year' => $year, 'data' => $data, 'line' => $line];
		}

		if ($errors !== []) {
			throw new ValidationException("Import aborted, nothing was written:\n" . implode("\n", $errors));
		}
		if ($plan === []) {
			throw new ValidationException('The file contains no data rows.');
		}
		return $plan;
	}

	/**
	 * Apply a plan produced by {@see plan()}.
	 *
	 * @param list<array{uid:string,typeId:int,year:int,data:array<string,mixed>,line:int}> $plan
	 * @return int rows applied
	 */
	public function apply(array $plan, string $actorUid): int {
		// All-or-nothing (see the class docstring): if any row fails part-way through
		// — a transient DB error, or a type deleted between plan() and apply() — the
		// whole batch rolls back rather than leaving "half the company imported".
		$this->db->beginTransaction();
		try {
			$applied = 0;
			foreach ($plan as $row) {
				$this->entitlements->setForEmployee($actorUid, $row['uid'], $row['year'], $row['typeId'], $row['data']);
				$applied++;
			}
			$this->db->commit();
			return $applied;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * @return list<array<string,string>> data rows keyed by lower-cased header
	 * @throws ValidationException
	 */
	private function parseCsv(string $csv): array {
		// Tolerate a UTF-8 BOM — the file usually comes straight out of Excel.
		$csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
		$lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
		if (count($lines) < 2) {
			throw new ValidationException('Expected a header row and at least one data row.');
		}
		// Semicolon support: German Excel exports ';'-separated CSV.
		$delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
		$header = array_map(static fn (string $h): string => strtolower(trim($h)), str_getcsv($lines[0], $delimiter, '"', ''));
		if (!in_array('user', $header, true)) {
			throw new ValidationException("The header row must contain a 'user' column.");
		}
		$rows = [];
		foreach (array_slice($lines, 1) as $line) {
			if (trim($line) === '') {
				continue;
			}
			$values = str_getcsv($line, $delimiter, '"', '');
			$row = [];
			foreach ($header as $i => $column) {
				$row[$column] = (string)($values[$i] ?? '');
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/** uid first, e-mail as the fallback spreadsheets usually carry. */
	private function resolveUser(string $identifier): ?string {
		if ($identifier === '') {
			return null;
		}
		if ($this->userManager->userExists($identifier)) {
			return $identifier;
		}
		if (str_contains($identifier, '@')) {
			$byEmail = $this->userManager->getByEmail($identifier);
			if (count($byEmail) === 1) {
				return $byEmail[0]->getUID();
			}
		}
		return null;
	}
}
