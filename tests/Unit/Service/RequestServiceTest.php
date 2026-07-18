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
use OCA\Absence\Db\RequestCommentMapper;
use OCA\Absence\Db\RequestEventMapper;
use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\ActivityPublisher;
use OCA\Absence\Service\CalendarService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\CoverageService;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Service\NotificationService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\RequestService;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RequestServiceTest extends TestCase {
	private LeaveRequestMapper&MockObject $requestMapper;
	private RequestCommentMapper&MockObject $commentMapper;
	private RequestEventMapper&MockObject $eventMapper;
	private LeaveTypeMapper&MockObject $leaveTypeMapper;
	private ManagerResolver&MockObject $managerResolver;
	private PermissionService&MockObject $permission;
	private CoverageService&MockObject $coverage;
	private CalendarService&MockObject $calendar;
	private NotificationService&MockObject $notifications;
	private ActivityPublisher&MockObject $activity;
	private ConfigService&MockObject $config;
	private IUserManager&MockObject $userManager;
	private LoggerInterface&MockObject $logger;
	private RequestService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);
		$this->commentMapper = $this->createMock(RequestCommentMapper::class);
		$this->eventMapper = $this->createMock(RequestEventMapper::class);
		$this->leaveTypeMapper = $this->createMock(LeaveTypeMapper::class);
		$this->managerResolver = $this->createMock(ManagerResolver::class);
		$this->permission = $this->createMock(PermissionService::class);
		$this->coverage = $this->createMock(CoverageService::class);
		$this->calendar = $this->createMock(CalendarService::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->activity = $this->createMock(ActivityPublisher::class);
		$this->config = $this->createMock(ConfigService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new RequestService(
			$this->requestMapper,
			$this->commentMapper,
			$this->eventMapper,
			$this->leaveTypeMapper,
			$this->managerResolver,
			$this->permission,
			$this->coverage,
			$this->calendar,
			$this->notifications,
			$this->activity,
			$this->config,
			$this->userManager,
			$this->logger,
		);
	}

	private function type(int $id, bool $employeeRequestable): LeaveType {
		$type = new LeaveType();
		$type->setId($id);
		$type->setKey('t' . $id);
		$type->setLabel('Type ' . $id);
		$type->setEnabled(true);
		$type->setEmployeeRequestable($employeeRequestable);
		return $type;
	}

	private function pendingOwnRequest(): LeaveRequest {
		$request = new LeaveRequest();
		$request->setId(5);
		$request->setEmployeeUid('emp');
		$request->setTypeId(1);
		$request->setStartDate('2026-02-10');
		$request->setEndDate('2026-02-12');
		$request->setWorkingDays(3.0);
		$request->setStatus(LeaveRequest::STATUS_PENDING);
		return $request;
	}

	public function testEmployeeCannotReclassifyIntoHrOnlyType(): void {
		$request = $this->pendingOwnRequest();
		$this->requestMapper->method('find')->with(5)->willReturn($request);
		$this->permission->method('canView')->willReturn(true);
		$this->permission->method('canModify')->willReturn(true);
		$this->permission->method('isHr')->with('emp')->willReturn(false);
		// Target type 9 is HR-recorded (not self-requestable).
		$this->leaveTypeMapper->method('find')->with(9)->willReturn($this->type(9, false));

		// The reclassification must be rejected and nothing persisted.
		$this->requestMapper->expects(self::never())->method('update');

		$this->expectException(ForbiddenException::class);
		$this->service->update('emp', 5, ['typeId' => 9]);
	}

	public function testAddCommentRejectsOverlongBody(): void {
		$request = $this->pendingOwnRequest();
		$this->requestMapper->method('find')->with(5)->willReturn($request);
		$this->permission->method('canView')->willReturn(true);
		$this->commentMapper->expects(self::never())->method('insert');

		$this->expectException(ValidationException::class);
		$this->service->addComment('emp', 5, str_repeat('a', 4001));
	}
}
