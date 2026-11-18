<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\CoverageService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Tests\Unit\L10nMockTrait;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CoverageServiceTest extends TestCase {
	use L10nMockTrait;
	private LeaveRequestMapper&MockObject $requestMapper;
	private ManagerResolver&MockObject $managerResolver;
	private PermissionService&MockObject $permission;
	private ConfigService&MockObject $config;
	private IUserManager&MockObject $userManager;
	private LeaveTypeMapper&MockObject $leaveTypeMapper;
	/** @var int[] type ids the mapper reports as confidential for this test */
	private array $hrOnlyTypeIds = [];
	private CoverageService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);
		$this->managerResolver = $this->createMock(ManagerResolver::class);
		$this->permission = $this->createMock(PermissionService::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->leaveTypeMapper = $this->createMock(LeaveTypeMapper::class);
		$this->leaveTypeMapper->method('hrOnlyTypeIds')->willReturnCallback(fn (): array => $this->hrOnlyTypeIds);
		$this->service = new CoverageService(
			$this->requestMapper,
			$this->leaveTypeMapper,
			$this->managerResolver,
			$this->permission,
			$this->config,
			new EmployeeDirectory(
				$this->userManager,
				$this->createMock(IGroupManager::class),
				$this->createMock(ConfigService::class),
				$this->createMock(\Psr\Log\LoggerInterface::class),
			),
			$this->userManager,
			$this->l10nMock(),
		);
	}

	private function request(int $id, string $employeeUid): LeaveRequest {
		$r = new LeaveRequest();
		$r->setId($id);
		$r->setEmployeeUid($employeeUid);
		$r->setTypeId(3);
		$r->setStartDate('2026-01-10');
		$r->setEndDate('2026-01-12');
		$r->setStatus(LeaveRequest::STATUS_APPROVED);
		return $r;
	}

	private function leave(int $id, string $employeeUid, string $start, string $end, ?string $managerUid = null): LeaveRequest {
		$r = $this->request($id, $employeeUid);
		$r->setStartDate($start);
		$r->setEndDate($end);
		$r->setManagerUid($managerUid);
		$r->setStatus(LeaveRequest::STATUS_PENDING);
		return $r;
	}

	public function testOthersOffForNamesTheTeamMatesOffDuringEachRequestOfAQueue(): void {
		$this->managerResolver->method('getDirectReports')->willReturnMap([
			['dave', ['alice', 'bob', 'charlie']],
			['eve', ['frank', 'grace']],
		]);
		$this->userManager->method('get')->willReturnCallback(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getDisplayName')->willReturn(ucfirst($uid) . ' Test');
			return $user;
		});
		$alice = $this->leave(1, 'alice', '2026-12-21', '2026-12-24', 'dave');
		$charlie = $this->leave(2, 'charlie', '2027-01-11', '2027-01-15', 'dave');
		$frank = $this->leave(3, 'frank', '2026-12-21', '2026-12-21', 'eve');

		// One read for the whole queue, over the dates of all its requests
		$this->requestMapper->expects(self::once())->method('findForEmployeesInRange')
			->with(self::callback(static function (array $uids): bool {
				sort($uids);
				return $uids === ['alice', 'bob', 'charlie', 'grace'];
			}), '2026-12-21', '2027-01-15')
			->willReturn([
				$alice,
				$charlie,
				$this->leave(10, 'bob', '2026-12-21', '2026-12-23'),
				$this->leave(11, 'bob', '2026-12-24', '2026-12-24'),
				$this->leave(12, 'grace', '2026-12-18', '2026-12-21'),
			]);

		$result = $this->service->othersOffFor([$alice, $charlie, $frank]);

		self::assertSame([
			1 => ['Bob Test'],
			2 => [],
			3 => ['Grace Test'],
		], $result);
	}

	public function testOthersOffForAnEmptyQueueReadsNothing(): void {
		$this->requestMapper->expects(self::never())->method('findForEmployeesInRange');

		self::assertSame([], $this->service->othersOffFor([]));
	}

	public function testNeutralPolicyHidesOthersTypeButKeepsOwn(): void {
		$this->config->method('getSharedCalendarVisibility')->willReturn(ConfigService::VISIBILITY_NEUTRAL);
		$this->config->method('getMaxConcurrentAbsences')->willReturn(0);
		$this->requestMapper->method('findForEmployeesInRange')->willReturn([
			$this->request(1, 'viewer'),
			$this->request(2, 'colleague'),
		]);

		$result = $this->service->getCoverage(['viewer', 'colleague'], '2026-01-01', '2026-01-31', null, 'viewer');
		$byUid = [];
		foreach ($result['events'] as $event) {
			$byUid[$event['employeeUid']] = $event['typeId'];
		}

		self::assertSame(3, $byUid['viewer'], 'The viewer sees their own leave type');
		self::assertNull($byUid['colleague'], 'A colleague\'s leave type is withheld under the neutral policy');
	}

	public function testRevealPolicyExposesType(): void {
		$this->config->method('getSharedCalendarVisibility')->willReturn(ConfigService::VISIBILITY_REVEAL);
		$this->config->method('getMaxConcurrentAbsences')->willReturn(0);
		$this->requestMapper->method('findForEmployeesInRange')->willReturn([
			$this->request(2, 'colleague'),
		]);

		$result = $this->service->getCoverage(['colleague'], '2026-01-01', '2026-01-31', null, 'viewer');

		self::assertSame(3, $result['events'][0]['typeId']);
	}

	public function testHrSeesTheTypeUnderTheNeutralPolicy(): void {
		// HR record sick leave and can open any request to read its type, so hiding it
		// on the who's-off timeline protects nothing — it only leaves the client with
		// no type to label the absence with, which used to render as a holiday.
		$this->config->method('getSharedCalendarVisibility')->willReturn(ConfigService::VISIBILITY_NEUTRAL);
		$this->config->method('getMaxConcurrentAbsences')->willReturn(0);
		$this->permission->method('isHr')->with('viewer')->willReturn(true);
		$this->requestMapper->method('findForEmployeesInRange')->willReturn([
			$this->request(2, 'colleague'),
		]);

		$result = $this->service->getCoverage(['colleague'], '2026-01-01', '2026-01-31', null, 'viewer');

		self::assertSame(3, $result['events'][0]['typeId']);
	}

	public function testAManagerSeesTheTypeOfTheirOwnReports(): void {
		$this->config->method('getSharedCalendarVisibility')->willReturn(ConfigService::VISIBILITY_NEUTRAL);
		$this->config->method('getMaxConcurrentAbsences')->willReturn(0);
		$this->permission->method('isHr')->willReturn(false);
		$this->managerResolver->method('getDirectReports')->with('boss')->willReturn(['report']);
		$this->requestMapper->method('findForEmployeesInRange')->willReturn([
			$this->request(2, 'report'),
			$this->request(3, 'somebody-elses-report'),
		]);

		$result = $this->service->getCoverage(['report', 'somebody-elses-report'], '2026-01-01', '2026-01-31', null, 'boss');
		$byUid = [];
		foreach ($result['events'] as $event) {
			$byUid[$event['employeeUid']] = $event['typeId'];
		}

		self::assertSame(3, $byUid['report'], 'Their own report, whose requests they decide');
		self::assertNull($byUid['somebody-elses-report'], 'Not their report — still withheld');
	}

	public function testAPeerStillLearnsNothingAboutAColleague(): void {
		// The protection the policy exists for: a plain colleague sees that somebody is
		// away and nothing about why.
		$this->config->method('getSharedCalendarVisibility')->willReturn(ConfigService::VISIBILITY_NEUTRAL);
		$this->config->method('getMaxConcurrentAbsences')->willReturn(0);
		$this->permission->method('isHr')->willReturn(false);
		$this->managerResolver->method('getDirectReports')->willReturn([]);
		$this->requestMapper->method('findForEmployeesInRange')->willReturn([
			$this->request(2, 'colleague'),
		]);

		$result = $this->service->getCoverage(['colleague'], '2026-01-01', '2026-01-31', null, 'peer');

		self::assertNull($result['events'][0]['typeId']);
	}

	public function testAnAnonymousCallerLearnsNothing(): void {
		// No viewer to check permissions against, so fail closed rather than reveal.
		$this->config->method('getSharedCalendarVisibility')->willReturn(ConfigService::VISIBILITY_NEUTRAL);
		$this->config->method('getMaxConcurrentAbsences')->willReturn(0);
		$this->permission->expects(self::never())->method('isHr');
		$this->requestMapper->method('findForEmployeesInRange')->willReturn([
			$this->request(2, 'colleague'),
		]);

		$result = $this->service->getCoverage(['colleague'], '2026-01-01', '2026-01-31', null, null);

		self::assertNull($result['events'][0]['typeId']);
	}

	public function testTheRevealPolicyDoesNotBotherResolvingPermissions(): void {
		// Everyone sees every non-confidential type anyway, so resolving the
		// viewer's reports would be waste. (The single isHr check stays: it also
		// decides confidential visibility, which "reveal" does not extend to.)
		$this->config->method('getSharedCalendarVisibility')->willReturn(ConfigService::VISIBILITY_REVEAL);
		$this->config->method('getMaxConcurrentAbsences')->willReturn(0);
		$this->managerResolver->expects(self::never())->method('getDirectReports');
		$this->requestMapper->method('findForEmployeesInRange')->willReturn([
			$this->request(2, 'colleague'),
		]);

		self::assertSame(3, $this->service->getCoverage(['colleague'], '2026-01-01', '2026-01-31', null, 'viewer')['events'][0]['typeId']);
	}

	public function testRejectsInvalidRange(): void {
		$this->expectException(ValidationException::class);
		$this->service->getCoverage(['viewer'], '2026-13-99', '2026-01-31', null, 'viewer');
	}

	public function testAConfidentialTypeIsNeutralEvenInRevealModeAndForOneself(): void {
		// §5.7: the admin's "reveal" setting, self-view and the manager
		// relationship stop mattering for confidential categories.
		$this->hrOnlyTypeIds = [3];
		$this->config->method('getSharedCalendarVisibility')->willReturn(ConfigService::VISIBILITY_REVEAL);
		$this->permission->method('isHr')->willReturn(false);
		$this->requestMapper->method('findForEmployeesInRange')->willReturn([$this->request(1, 'viewer')]);

		$result = $this->service->getCoverage(['viewer'], '2026-01-01', '2026-01-31', null, 'viewer');

		self::assertNull($result['events'][0]['typeId']);
	}

	public function testAConfidentialTypeIsVisibleToHr(): void {
		$this->hrOnlyTypeIds = [3];
		$this->config->method('getSharedCalendarVisibility')->willReturn(ConfigService::VISIBILITY_NEUTRAL);
		$this->permission->method('isHr')->with('hr')->willReturn(true);
		$this->requestMapper->method('findForEmployeesInRange')->willReturn([$this->request(1, 'colleague')]);

		$result = $this->service->getCoverage(['colleague'], '2026-01-01', '2026-01-31', null, 'hr');

		self::assertSame(3, $result['events'][0]['typeId']);
	}

	/**
	 * Bob's leave status, whether the caller is HR, whether Bob is their peer,
	 * whether the team calendar is on and the app set up, and the answer.
	 *
	 * @return array<string, array{string, bool, bool, bool, bool, ?bool}>
	 */
	public static function awayCases(): array {
		return [
			'HR, leave asked for' => [LeaveRequest::STATUS_PENDING, true, false, false, true, true],
			'a teammate, leave asked for' => [LeaveRequest::STATUS_ESCALATED, false, true, false, true, true],
			'anybody else, shared calendar, approved' => [LeaveRequest::STATUS_APPROVED, false, false, true, true, true],
			'anybody else, shared calendar, withdrawal pending' => [LeaveRequest::STATUS_WITHDRAWAL_PENDING, false, false, true, true, true],
			'anybody else, shared calendar, leave asked for' => [LeaveRequest::STATUS_PENDING, false, false, true, true, false],
			'anybody else, shared calendar, escalated' => [LeaveRequest::STATUS_ESCALATED, false, false, true, true, false],
			'anybody else, team calendar off' => [LeaveRequest::STATUS_APPROVED, false, false, false, true, null],
			'anybody else, app not set up' => [LeaveRequest::STATUS_APPROVED, false, false, true, false, null],
		];
	}

	#[DataProvider('awayCases')]
	public function testAwayCountsOnlyWhatTheCallerSeesElsewhere(string $status, bool $hr, bool $peer, bool $shared, bool $configured, ?bool $expected): void {
		$this->givenEmployees();
		$this->permission->method('isHr')->willReturn($hr);
		$this->managerResolver->method('getPeers')->with('alice')->willReturn($peer ? ['bob'] : []);
		$this->config->method('isCalDavSharedEnabled')->willReturn($shared);
		$this->config->method('isConfigured')->willReturn($configured);
		$bob = $this->leave(1, 'bob', '2027-03-08', '2027-03-10');
		$bob->setStatus($status);
		$this->requestMapper->method('findForEmployeesInRange')->willReturnCallback(
			static fn (array $uids, string $from, string $to, array $statuses): array => in_array($bob->getStatus(), $statuses, true) ? [$bob] : [],
		);

		self::assertSame($expected, $this->service->isAway('alice', 'bob', '2027-03-08', '2027-03-12'));
	}

	public function testNobodyIsToldWhetherSomebodyIsAnEmployee(): void {
		$this->givenEmployees();
		$this->permission->method('isHr')->willReturn(true);
		$this->requestMapper->expects(self::never())->method('findForEmployeesInRange');

		self::assertNull($this->service->isAway('hr', 'nobody', '2027-03-08', '2027-03-12'));
	}

	public function testABadRangeIsRefusedAlikeForEmployeesAndOthers(): void {
		$this->givenEmployees();
		$messages = [];
		foreach (['bob', 'nobody'] as $uid) {
			try {
				$this->service->isAway('alice', $uid, '2027-01-01', '2028-01-03');
			} catch (ValidationException $e) {
				$messages[$uid] = $e->getMessage();
			}
		}

		self::assertSame(['bob' => 'The selected date range is too large.', 'nobody' => 'The selected date range is too large.'], $messages);
	}

	/**
	 * Every caller is an employee, and every uid but "nobody" is one.
	 */
	private function givenEmployees(): void {
		$this->permission->method('isEmployeeOrHr')->willReturn(true);
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): ?IUser => $uid === 'nobody' ? null : $this->createMock(IUser::class));
	}
}
