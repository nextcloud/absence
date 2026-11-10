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
 * Expected columns (header row required, order free, other columns ignored;
 * every row has as many fields as the header, and a user, type and year only
 * once per file):
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
		private IDBConnection $db,
		private IUserManager $userManager,
	) {
	}

	/**
	 * Parse and validate the whole file, resolving users and types. Each row names
	 * the account it resolved to, so an e-mail address can be checked before applying.
	 *
	 * @return list<array{uid:string,name:string,typeId:int,year:int,data:array<string,mixed>,line:int}>
	 * @throws ValidationException listing every broken line
	 */
	public function plan(string $csv, int $defaultYear): array {
		[$header, $records] = $this->parseCsv($csv);
		$typesByKey = [];
		foreach ($this->leaveTypeMapper->findAll() as $type) {
			$typesByKey[$type->getKey()] = $type;
		}

		$plan = [];
		$errors = [];
		/** @var array<string, int> $seen user/type/year => the line that set it */
		$seen = [];
		foreach ($records as $line => $values) {
			if (count($values) !== count($header)) {
				$errors[] = "line $line: " . count($values) . ' fields, the header has ' . count($header);
				continue;
			}
			$row = array_combine($header, $values);
			$uid = $this->employees->resolveUser(trim($row['user'] ?? ''));
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
			$key = $uid . '/' . $type->getId() . '/' . $year;
			if (isset($seen[$key])) {
				$errors[] = "line $line: same user, type and year as line {$seen[$key]}";
				continue;
			}
			$seen[$key] = $line;

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

			$plan[] = ['uid' => $uid, 'name' => $this->userManager->get($uid)?->getDisplayName() ?? $uid, 'typeId' => (int)$type->getId(), 'year' => $year, 'data' => $data, 'line' => $line];
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
	 * @param list<array{uid:string,name:string,typeId:int,year:int,data:array<string,mixed>,line:int}> $plan
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
	 * @return array{0: list<string>, 1: array<int, list<string>>} the lower-cased header, and the data rows by the line they start on
	 * @throws ValidationException
	 */
	private function parseCsv(string $csv): array {
		// Tolerate a UTF-8 BOM — the file usually comes straight out of Excel.
		$csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
		// One kind of line ending, so that counting them gives the line of a row
		$csv = trim(str_replace(["\r\n", "\r"], "\n", $csv));
		$firstLine = strstr($csv, "\n", true);
		if ($firstLine === false) {
			throw new ValidationException('Expected a header row and at least one data row.');
		}
		// Semicolon support: German Excel exports ';'-separated CSV.
		$delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

		$file = new \SplTempFileObject();
		$file->fwrite($csv);
		$file->rewind();
		$header = null;
		$records = [];
		$line = 1;
		$position = 0;
		while (!$file->eof()) {
			$startLine = $line;
			// A quoted field may span lines
			$values = $file->fgetcsv($delimiter, '"', '');
			$end = (int)$file->ftell();
			$line += substr_count($csv, "\n", $position, $end - $position);
			$position = $end;
			if (!is_array($values) || (count($values) === 1 && trim((string)$values[0]) === '')) {
				continue;
			}
			$values = array_map(strval(...), array_values($values));
			if ($header === null) {
				$header = array_map(static fn (string $h): string => strtolower(trim($h)), $values);
				continue;
			}
			$records[$startLine] = $values;
		}
		if ($header === null || !in_array('user', $header, true)) {
			throw new ValidationException("The header row must contain a 'user' column.");
		}
		return [$header, $records];
	}
}
