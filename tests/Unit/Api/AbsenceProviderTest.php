<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Api;

use OCA\Absence\Api\Absence;
use OCA\Absence\Api\AbsenceProvider;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\PersonalDefaultsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AbsenceProviderTest extends TestCase {
	private LeaveRequestMapper&MockObject $requestMapper;
	private PersonalDefaultsService&MockObject $personalDefaults;
	private AbsenceProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);
		$leaveTypeMapper = $this->createMock(LeaveTypeMapper::class);
		$leaveTypeMapper->method('findAll')->willReturn([
			$this->type(1, 'annual', false),
			$this->type(8, 'parental', true),
		]);
		$this->personalDefaults = $this->createMock(PersonalDefaultsService::class);
		$this->provider = new AbsenceProvider($this->requestMapper, $leaveTypeMapper, $this->personalDefaults);
	}

	private function type(int $id, string $key, bool $hrOnly): LeaveType {
		$type = new LeaveType();
		$type->setId($id);
		$type->setKey($key);
		$type->setLabel(ucfirst($key));
		$type->setHrOnly($hrOnly);
		return $type;
	}

	private function request(int $id, int $typeId, string $start, string $status): LeaveRequest {
		$request = new LeaveRequest();
		$request->setId($id);
		$request->setEmployeeUid('alice');
		$request->setTypeId($typeId);
		$request->setStartDate($start);
		$request->setEndDate($start);
		$request->setWorkingDays(1.0);
		$request->setStatus($status);
		return $request;
	}

	public function testApprovedOnlyByDefault(): void {
		$this->requestMapper->expects(self::once())->method('findForEmployeesInRange')
			->with(['alice'], '2026-01-01', '2026-01-31', LeaveRequest::USED_STATUSES)
			->willReturn([]);
		self::assertSame([], $this->provider->getAbsencesForUsers(['alice', 'alice'], '2026-01-01', '2026-01-31'));
	}

	public function testPendingOnRequest(): void {
		$this->requestMapper->expects(self::once())->method('findForEmployeesInRange')
			->with(['alice'], '2026-01-01', '2026-01-31', [...LeaveRequest::USED_STATUSES, ...LeaveRequest::PENDING_STATUSES])
			->willReturn([$this->request(2, 1, '2026-01-12', LeaveRequest::STATUS_ESCALATED)]);
		$absences = $this->provider->getAbsencesForUsers(['alice'], '2026-01-01', '2026-01-31', true);
		self::assertSame(Absence::STATUS_PENDING, $absences[0]->status);
		self::assertSame('annual', $absences[0]->typeKey);
	}

	public function testSortedByStartDate(): void {
		$this->requestMapper->method('findForEmployeesInRange')->willReturn([
			$this->request(3, 1, '2026-01-20', LeaveRequest::STATUS_APPROVED),
			$this->request(4, 1, '2026-01-05', LeaveRequest::STATUS_APPROVED),
		]);
		$absences = $this->provider->getAbsencesForUsers(['alice'], '2026-01-01', '2026-01-31');
		self::assertSame([4, 3], array_map(static fn (Absence $a): int => $a->id, $absences));
	}

	public function testLongUserListsAreQueriedInChunks(): void {
		// Oracle refuses more than 1000 values in an IN () list
		$uids = array_map(static fn (int $i): string => 'user' . $i, range(1, 2001));
		$this->requestMapper->expects(self::exactly(3))->method('findForEmployeesInRange')->willReturn([]);
		$this->provider->getAbsencesForUsers($uids, '2026-01-01', '2026-01-31');
	}

	public function testEveryoneInRangeKeepsOnlyActiveRequests(): void {
		$this->requestMapper->method('findAllInRange')->with('2026-01-01', '2026-01-31')->willReturn([
			$this->request(7, 1, '2026-01-20', LeaveRequest::STATUS_REJECTED),
			$this->request(8, 1, '2026-01-12', LeaveRequest::STATUS_PENDING),
			$this->request(9, 1, '2026-01-05', LeaveRequest::STATUS_APPROVED),
		]);
		self::assertSame([9], array_map(static fn (Absence $a): int => $a->id, $this->provider->getAbsencesForEveryone('2026-01-01', '2026-01-31')));
		self::assertSame([9, 8], array_map(static fn (Absence $a): int => $a->id, $this->provider->getAbsencesForEveryone('2026-01-01', '2026-01-31', true)));
	}

	public function testFieldsOverrideTheStoredOnes(): void {
		$absence = $this->provider->toAbsence($this->request(10, 1, '2026-03-10', LeaveRequest::STATUS_APPROVED), ['startDate' => '2026-03-02', 'endDate' => '2026-03-04', 'workingDays' => 3.0]);
		self::assertSame(['2026-03-02', '2026-03-04', 3.0], [$absence->startDate, $absence->endDate, $absence->workingDays]);
	}

	public function testConfidentialCategoriesStayConfidential(): void {
		// §5.7: nobody outside HR learns that this is parental leave
		$absence = $this->provider->toAbsence($this->request(5, 8, '2026-02-02', LeaveRequest::STATUS_APPROVED));
		self::assertTrue($absence->confidential);
		self::assertNull($absence->typeKey);
		self::assertNull($absence->typeLabel);
		self::assertSame(Absence::STATUS_APPROVED, $absence->status);
	}

	public function testUnknownTypeIsTreatedAsConfidential(): void {
		$absence = $this->provider->toAbsence($this->request(6, 99, '2026-02-02', LeaveRequest::STATUS_APPROVED));
		self::assertTrue($absence->confidential);
	}

	public function testWorkSettingsComeFromThePersonalDefaults(): void {
		$this->personalDefaults->method('resolve')->with('alice')->willReturn([
			'workWeekdays' => '4,2,3,9,2',
			'holidayCountry' => 'de',
			'holidayRegion' => 'by',
		]);
		self::assertSame(
			['workWeekdays' => [2, 3, 4], 'holidayCountry' => 'DE', 'holidayRegion' => 'BY'],
			$this->provider->getWorkingDaysSettings('alice'),
		);
	}

	public function testWorkSettingsWithoutHolidayRegion(): void {
		$this->personalDefaults->method('resolve')->willReturn([
			'workWeekdays' => '1,2,3,4,5',
			'holidayCountry' => '',
			'holidayRegion' => '',
		]);
		$settings = $this->provider->getWorkingDaysSettings('bob');
		self::assertNull($settings['holidayCountry']);
		self::assertNull($settings['holidayRegion']);
	}

	public function testStatuses(): void {
		self::assertSame(Absence::STATUS_APPROVED, AbsenceProvider::status(LeaveRequest::STATUS_WITHDRAWAL_PENDING));
		self::assertSame(Absence::STATUS_PENDING, AbsenceProvider::status(LeaveRequest::STATUS_PENDING));
		self::assertSame(Absence::STATUS_CLOSED, AbsenceProvider::status(LeaveRequest::STATUS_REJECTED));
		self::assertSame(Absence::STATUS_CLOSED, AbsenceProvider::status(LeaveRequest::STATUS_CANCELLED));
	}
}
