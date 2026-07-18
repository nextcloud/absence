<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCP\IUserManager;

/**
 * CSV export for HR (spec §13). XLSX is offered as a documented CSV fallback so the
 * app carries no heavy spreadsheet dependency in phase 1.
 */
class ExportService {
	use DateRangeTrait;

	public function __construct(
		private LeaveRequestMapper $requestMapper,
		private LeaveTypeMapper $leaveTypeMapper,
		private ReportService $reportService,
		private IUserManager $userManager,
	) {
	}

	/**
	 * @return array{filename:string,content:string}
	 */
	public function requestsCsv(string $from, string $to): array {
		[$from, $to] = $this->assertValidRange($from, $to);
		$types = $this->typeLabels();
		$rows = [[
			'ID', 'Employee', 'Manager', 'Type', 'Start', 'End', 'Working days', 'Status', 'Decided by', 'Decided at',
		]];
		foreach ($this->requestMapper->findAllInRange($from, $to) as $r) {
			$rows[] = [
				(string)$r->getId(),
				$this->displayName($r->getEmployeeUid()),
				$r->getManagerUid() !== null ? $this->displayName($r->getManagerUid()) : '',
				$types[$r->getTypeId()] ?? (string)$r->getTypeId(),
				$r->getStartDate(),
				$r->getEndDate(),
				(string)$r->getWorkingDays(),
				$r->getStatus(),
				$r->getDecidedBy() !== null ? $this->displayName($r->getDecidedBy()) : '',
				$r->getDecidedAt()?->format('Y-m-d H:i') ?? '',
			];
		}
		return ['filename' => "absence-requests-{$from}_{$to}.csv", 'content' => $this->toCsv($rows)];
	}

	/**
	 * @return array{filename:string,content:string}
	 */
	public function balancesCsv(int $year): array {
		$report = $this->reportService->balancesReport($year);
		$rows = [[
			'Employee', 'Year', 'Type', 'Entitlement', 'Base', 'Carry-over', 'Adjustment', 'Used', 'Pending', 'Remaining', 'Available',
		]];
		foreach ($report as $entry) {
			$rows[] = [
				$entry['displayName'],
				(string)$entry['year'],
				$entry['typeLabel'],
				(string)($entry['entitlement'] ?? ''),
				(string)$entry['baseDays'],
				(string)$entry['carryOverDays'],
				(string)$entry['manualAdjustment'],
				(string)$entry['used'],
				(string)$entry['pending'],
				(string)($entry['remaining'] ?? ''),
				(string)($entry['available'] ?? ''),
			];
		}
		return ['filename' => "absence-balances-{$year}.csv", 'content' => $this->toCsv($rows)];
	}

	/**
	 * @param list<list<string>> $rows
	 */
	private function toCsv(array $rows): string {
		$handle = fopen('php://temp', 'r+');
		// BOM so Excel opens UTF-8 correctly.
		fwrite($handle, "\xEF\xBB\xBF");
		foreach ($rows as $row) {
			// escape: '' disables PHP's legacy backslash escaping in favour of pure
			// RFC-4180 quoting (and silences the 8.4 deprecation of the default).
			fputcsv($handle, array_map([$this, 'sanitizeCell'], $row), ',', '"', '');
		}
		rewind($handle);
		$content = stream_get_contents($handle);
		fclose($handle);
		return $content === false ? '' : $content;
	}

	/**
	 * Neutralize spreadsheet formula injection: a cell that begins with a formula
	 * trigger (=, +, -, @, tab or CR) is evaluated by Excel/LibreOffice/Sheets when
	 * the export is opened. Employee display names and leave-type labels are
	 * attacker-influenced free text, so prefix any such value with an apostrophe,
	 * which forces the client to treat the whole cell as literal text.
	 */
	private function sanitizeCell(string $value): string {
		if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * @return array<int,string>
	 */
	private function typeLabels(): array {
		$labels = [];
		foreach ($this->leaveTypeMapper->findAll() as $type) {
			$labels[$type->getId()] = $type->getLabel();
		}
		return $labels;
	}

	private function displayName(string $uid): string {
		$user = $this->userManager->get($uid);
		return $user !== null ? $user->getDisplayName() : $uid;
	}
}
