<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\CoverageService;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Service\PermissionService;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CoverageServiceTest extends TestCase {
	private LeaveRequestMapper&MockObject $requestMapper;
	private ManagerResolver&MockObject $managerResolver;
	private PermissionService&MockObject $permission;
	private ConfigService&MockObject $config;
	private IUserManager&MockObject $userManager;
	private CoverageService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);
		$this->managerResolver = $this->createMock(ManagerResolver::class);
		$this->permission = $this->createMock(PermissionService::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->service = new CoverageService(
			$this->requestMapper,
			$this->managerResolver,
			$this->permission,
			$this->config,
			$this->userManager,
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

	public function testRejectsInvalidRange(): void {
		$this->expectException(ValidationException::class);
		$this->service->getCoverage(['viewer'], '2026-13-99', '2026-01-31', null, 'viewer');
	}
}
