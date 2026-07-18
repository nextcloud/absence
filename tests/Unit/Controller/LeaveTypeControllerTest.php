<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Controller;

use OCA\Absence\Controller\LeaveTypeController;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LeaveTypeControllerTest extends TestCase {
	private LeaveTypeMapper&MockObject $mapper;
	private PermissionService&MockObject $permission;
	private LeaveTypeController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(LeaveTypeMapper::class);
		$this->permission = $this->createMock(PermissionService::class);
		$this->controller = new LeaveTypeController(
			'absence',
			$this->createMock(IRequest::class),
			'hr-user',
			$this->mapper,
			$this->permission,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testCreateRejectsInvalidColor(): void {
		$this->mapper->expects(self::never())->method('insert');
		$response = $this->controller->create('annual', 'Annual leave', 'red; background:url(//evil)');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testCreateRejectsOverlongLabel(): void {
		$this->mapper->expects(self::never())->method('insert');
		$response = $this->controller->create('annual', str_repeat('a', 129));
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testCreateRejectsEmptyKey(): void {
		$this->mapper->expects(self::never())->method('insert');
		$response = $this->controller->create('   ', 'Annual leave');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testCreateAcceptsValidInput(): void {
		$this->mapper->method('insert')->willReturnCallback(static function (LeaveType $t): LeaveType {
			$t->setId(1);
			return $t;
		});
		$response = $this->controller->create('annual', 'Annual leave', '#0082c9', '🌴');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('annual', $response->getData()['key']);
	}
}
