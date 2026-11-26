<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\CalendarService;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

class CalendarServiceTest extends TestCase {
	private const VIEWER = 'viewer';
	private const ANNUAL = 1;
	private const MATERNITY = 5;

	private ConfigService&MockObject $config;
	private LeaveRequestMapper&MockObject $requestMapper;
	private PermissionService&MockObject $permission;
	private EmployeeDirectory&MockObject $employees;
	private ClockService&MockObject $clock;
	private IFactory&MockObject $l10nFactory;
	/** @var array<string, string> translations of the viewer's language */
	private array $translations = [];

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(ConfigService::class);
		$this->config->method('isCalDavSharedEnabled')->willReturn(true);
		$this->config->method('isConfigured')->willReturn(true);
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);
		$this->permission = $this->createMock(PermissionService::class);
		$this->permission->method('isEmployeeOrHr')->willReturn(true);
		$this->employees = $this->createMock(EmployeeDirectory::class);
		$this->employees->method('activeDisplayNames')->willReturn(['alice' => 'Alice Example', 'bob' => 'Bob Example', '90817' => 'Numeric Example']);
		$this->clock = $this->createMock(ClockService::class);
		$this->clock->method('serverYear')->willReturn(2026);
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->l10nFactory->method('getUserLanguage')->willReturnCallback(
			static fn (IUser $user): string => $user->getUID() === self::VIEWER ? 'de' : 'en',
		);
		$this->l10nFactory->method('get')->willReturnCallback(function (string $app, ?string $lang): IL10N {
			$l = $this->createMock(IL10N::class);
			$l->method('t')->willReturnCallback(fn (string $text): string => $lang === 'de' ? ($this->translations[$text] ?? $text) : $text);
			return $l;
		});
	}

	private function service(): CalendarService {
		$types = $this->createMock(LeaveTypeMapper::class);
		$types->method('find')->willReturnCallback(static function (int $id): LeaveType {
			$type = new LeaveType();
			$type->setId($id);
			match ($id) {
				self::ANNUAL => $type->setLabel('Annual leave'),
				self::MATERNITY => $type->setLabel('Maternity leave'),
				default => throw new DoesNotExistException('No type ' . $id),
			};
			$type->setHrOnly($id === self::MATERNITY);
			return $type;
		});
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			return $user;
		});
		return new CalendarService(
			$this->config,
			$this->requestMapper,
			$types,
			$users,
			$this->l10nFactory,
			$this->permission,
			$this->employees,
			$this->clock,
		);
	}

	private function leave(int $id, string $uid, int $typeId = self::ANNUAL, string $start = '2026-07-06', string $end = '2026-07-10'): LeaveRequest {
		$request = new LeaveRequest();
		$request->setId($id);
		$request->setEmployeeUid($uid);
		$request->setTypeId($typeId);
		$request->setStartDate($start);
		$request->setEndDate($end);
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		$request->setUpdatedAt(new \DateTime('2026-06-01 09:00:00'));
		return $request;
	}

	/**
	 * @return array<string, \Sabre\VObject\Component\VEvent>
	 */
	private function teamEvents(): array {
		return array_map(
			static fn (array $event) => Reader::read($event['ics'])->VEVENT,
			$this->service()->teamEvents(self::VIEWER),
		);
	}

	public function testItHoldsTheEmployeesLeaveAsTransparentAllDayEvents(): void {
		$this->requestMapper->method('findInForceBetween')->willReturn([
			$this->leave(1, 'alice'),
			$this->leave(2, 'zoe'),
			$this->leave(3, 'bob', self::ANNUAL, '2026-12-31', '2027-01-04'),
			$this->leave(4, '90817'),
		]);

		$events = $this->teamEvents();

		self::assertSame(['absence-team-1.ics', 'absence-team-3.ics', 'absence-team-4.ics'], array_keys($events));
		$event = $events['absence-team-1.ics'];
		self::assertSame('absence-team-1', (string)$event->UID);
		self::assertSame('20260706', (string)$event->DTSTART);
		self::assertSame('20260711', (string)$event->DTEND);
		self::assertSame('TRANSPARENT', (string)$event->TRANSP);
		self::assertSame('20270105', (string)$events['absence-team-3.ics']->DTEND);
		self::assertSame('Numeric Example · Absent', (string)$events['absence-team-4.ics']->SUMMARY);
	}

	public static function years(): array {
		return [
			'2026' => [2026, '2025-01-01', '2027-12-31'],
			'2027' => [2027, '2026-01-01', '2028-12-31'],
		];
	}

	#[DataProvider('years')]
	public function testItCoversThePreviousTheCurrentAndTheNextYear(int $year, string $from, string $to): void {
		$this->clock = $this->createMock(ClockService::class);
		$this->clock->method('serverYear')->willReturn($year);
		$this->requestMapper->expects(self::once())->method('findInForceBetween')->with($from, $to)->willReturn([]);

		self::assertSame([], $this->service()->teamEvents(self::VIEWER));
	}

	public static function titles(): array {
		return [
			'neutral' => [ConfigService::VISIBILITY_NEUTRAL, self::ANNUAL, 'Alice Example · Absent'],
			'revealed' => [ConfigService::VISIBILITY_REVEAL, self::ANNUAL, 'Alice Example · Annual leave'],
			'confidential, neutral' => [ConfigService::VISIBILITY_NEUTRAL, self::MATERNITY, 'Alice Example · Absent'],
			'confidential, revealed' => [ConfigService::VISIBILITY_REVEAL, self::MATERNITY, 'Alice Example · Absent'],
		];
	}

	#[DataProvider('titles')]
	public function testTitlesNameTheTypeOnlyWhenRevealedAndNeverAConfidentialOne(string $visibility, int $typeId, string $title): void {
		$this->config->method('getSharedCalendarVisibility')->willReturn($visibility);
		$this->requestMapper->method('findInForceBetween')->willReturn([$this->leave(1, 'alice', $typeId)]);

		self::assertSame($title, (string)$this->teamEvents()['absence-team-1.ics']->SUMMARY);
	}

	public function testItSpeaksTheViewersLanguage(): void {
		$this->translations = ['Absent' => 'Abwesend', 'Team absences' => 'Team-Abwesenheiten'];
		$this->config->method('getSharedCalendarVisibility')->willReturn(ConfigService::VISIBILITY_NEUTRAL);
		$this->requestMapper->method('findInForceBetween')->willReturn([$this->leave(1, 'alice')]);

		self::assertSame('Alice Example · Abwesend', (string)$this->teamEvents()['absence-team-1.ics']->SUMMARY);
		self::assertSame('Team-Abwesenheiten', $this->service()->teamCalendarName(self::VIEWER));
	}

	public static function audiences(): array {
		return [
			'employee or HR' => [true, true, true, true],
			'switched off' => [false, true, true, false],
			'not set up' => [true, false, true, false],
			'neither employee nor HR' => [true, true, false, false],
		];
	}

	#[DataProvider('audiences')]
	public function testOnlyEmployeesAndHrGetItWhileItIsOnAndTheAppIsSetUp(bool $on, bool $configured, bool $employeeOrHr, bool $expected): void {
		$this->config = $this->createMock(ConfigService::class);
		$this->config->method('isCalDavSharedEnabled')->willReturn($on);
		$this->config->method('isConfigured')->willReturn($configured);
		$this->permission = $this->createMock(PermissionService::class);
		$this->permission->method('isEmployeeOrHr')->with(self::VIEWER)->willReturn($employeeOrHr);
		$this->requestMapper->expects($expected ? self::once() : self::never())->method('findInForceBetween')->willReturn([$this->leave(1, 'alice')]);

		$service = $this->service();

		self::assertSame($expected, $service->hasTeamCalendar(self::VIEWER));
		self::assertSame($expected ? ['absence-team-1.ics'] : [], array_keys($service->teamEvents(self::VIEWER)));
	}
}
