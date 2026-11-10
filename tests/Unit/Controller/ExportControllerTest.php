<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Controller;

use OCA\Absence\Controller\ExportController;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\ExportService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\ReportService;
use OCA\Absence\Tests\Unit\L10nMockTrait;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class ExportControllerTest extends TestCase {
	use L10nMockTrait;

	public function testAnInvalidRangeIsAClientError(): void {
		$permission = $this->createMock(PermissionService::class);
		$permission->method('isHr')->willReturn(true);
		$controller = new ExportController(
			'absence',
			$this->createMock(IRequest::class),
			'itest-hr',
			new ExportService(
				$this->createMock(LeaveRequestMapper::class),
				$this->createMock(LeaveTypeMapper::class),
				$this->createMock(ReportService::class),
				$this->createMock(EmployeeDirectory::class),
				$this->createMock(IUserManager::class),
				$this->l10nMock(),
			),
			$permission,
			$this->createMock(ClockService::class),
			$this->l10nMock(),
		);

		$response = $controller->requests('2027-02-01', '2027-01-01');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(['message' => 'The end date must be on or after the start date.'], $response->getData());
	}
}
