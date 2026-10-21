<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Api;

use OCA\Absence\Db\Holiday;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\HolidayService;
use OCA\Absence\Service\PersonalDefaultsService;

class AbsenceProvider implements IAbsenceProvider {
	public function __construct(
		private LeaveRequestMapper $requestMapper,
		private LeaveTypeMapper $leaveTypeMapper,
		private PersonalDefaultsService $personalDefaults,
		private HolidayService $holidays,
	) {
	}

	/** Oracle refuses more than 1000 values in an IN () list */
	private const CHUNK = 1000;

	#[\Override]
	public function getAbsencesForUsers(array $userIds, string $from, string $to, bool $includePending = false): array {
		$requests = [];
		foreach (array_chunk(array_values(array_unique($userIds)), self::CHUNK) as $chunk) {
			array_push($requests, ...$this->requestMapper->findForEmployeesInRange($chunk, $from, $to, self::statuses($includePending)));
		}
		return $this->toSortedAbsences($requests);
	}

	#[\Override]
	public function getAbsencesForEveryone(string $from, string $to, bool $includePending = false): array {
		$statuses = self::statuses($includePending);
		return $this->toSortedAbsences(array_values(array_filter(
			$this->requestMapper->findAllInRange($from, $to),
			static fn (LeaveRequest $request): bool => in_array($request->getStatus(), $statuses, true),
		)));
	}

	#[\Override]
	public function getWorkingDaysSettings(string $userId): array {
		$defaults = $this->personalDefaults->resolve($userId);
		$weekdays = array_values(array_unique(array_filter(
			array_map('intval', explode(',', (string)$defaults['workWeekdays'])),
			static fn (int $day): bool => $day >= 1 && $day <= 7,
		)));
		sort($weekdays);
		return [
			'workWeekdays' => $weekdays,
			'holidayCountry' => $defaults['holidayCountry'] !== '' ? strtoupper((string)$defaults['holidayCountry']) : null,
			'holidayRegion' => $defaults['holidayRegion'] !== '' ? strtoupper((string)$defaults['holidayRegion']) : null,
		];
	}

	#[\Override]
	public function getPublicHolidays(array $regionCodes, string $from, string $to): array {
		return array_map(static fn (Holiday $h): array => [
			'date' => $h->getDate(),
			'region' => $h->getRegion(),
			'label' => $h->getLabel(),
		], $this->holidays->forRegions($regionCodes, $from, $to));
	}

	/**
	 * Map a request to the public shape, withholding confidential categories (§5.7).
	 *
	 * @param array<string,mixed>|null $fields overrides, e.g. the dates before an edit
	 */
	public function toAbsence(LeaveRequest $request, ?array $fields = null): Absence {
		$typeId = (int)($fields['typeId'] ?? $request->getTypeId());
		$type = $this->type($typeId);
		$confidential = $type === null || $type->getHrOnly();
		return new Absence(
			(int)$request->getId(),
			$request->getEmployeeUid(),
			substr((string)($fields['startDate'] ?? $request->getStartDate()), 0, 10),
			substr((string)($fields['endDate'] ?? $request->getEndDate()), 0, 10),
			(float)($fields['workingDays'] ?? $request->getWorkingDays()),
			self::status($request->getStatus()),
			$confidential ? null : $type->getKey(),
			$confidential ? null : $type->getLabel(),
			$confidential,
		);
	}

	/**
	 * @return list<string>
	 */
	private static function statuses(bool $includePending): array {
		return $includePending
			? [...LeaveRequest::USED_STATUSES, ...LeaveRequest::PENDING_STATUSES]
			: LeaveRequest::USED_STATUSES;
	}

	/**
	 * @param list<LeaveRequest> $requests
	 * @return list<Absence>
	 */
	private function toSortedAbsences(array $requests): array {
		usort($requests, static fn (LeaveRequest $a, LeaveRequest $b): int => [$a->getStartDate(), $a->getId()] <=> [$b->getStartDate(), $b->getId()]);
		return array_map(fn (LeaveRequest $request): Absence => $this->toAbsence($request), $requests);
	}

	public static function status(string $status): string {
		return match (true) {
			in_array($status, LeaveRequest::USED_STATUSES, true) => Absence::STATUS_APPROVED,
			in_array($status, LeaveRequest::PENDING_STATUSES, true) => Absence::STATUS_PENDING,
			default => Absence::STATUS_CLOSED,
		};
	}

	private function type(int $typeId): ?LeaveType {
		foreach ($this->leaveTypeMapper->findAll() as $type) {
			if ($type->getId() === $typeId) {
				return $type;
			}
		}
		return null;
	}
}
