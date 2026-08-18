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
use OCP\IUserManager;
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
}
