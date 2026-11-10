<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Command;

use OCA\Absence\Command\ImportEmployees;
use OCA\Absence\Command\ImportEntitlements;
use OCA\Absence\Command\ImportLeave;
use OCA\Absence\Exception\ConflictException;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\EmployeeJsonService;
use OCA\Absence\Service\ImportService;
use OCA\Absence\Service\LeaveImportService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What the import commands do with a refusal from the services: say why and
 * exit with 1. And who they write as: nobody.
 */
class ImportCommandsTest extends TestCase {
	private string $file = '';

	protected function setUp(): void {
		parent::setUp();
		$this->file = (string)tempnam(sys_get_temp_dir(), 'absence-import');
		file_put_contents($this->file, 'read by the mocked service');
	}

	protected function tearDown(): void {
		unlink($this->file);
		parent::tearDown();
	}

	public function testEntitlementsAreWrittenWithoutAnActor(): void {
		$plan = [['uid' => 'alice', 'name' => 'Alice', 'typeId' => 1, 'year' => 2026, 'data' => ['baseDays' => 28.0], 'line' => 2]];
		$service = $this->createMock(ImportService::class);
		$service->method('plan')->willReturn($plan);
		$service->expects(self::once())->method('apply')->with($plan, null)->willReturn(1);

		self::assertSame(0, $this->execute(new ImportEntitlements($service))->getStatusCode());
	}

	public function testARefusedEntitlementWriteExitsWithItsMessage(): void {
		$service = $this->createMock(ImportService::class);
		$service->method('plan')->willReturn([['uid' => 'alice', 'name' => 'Alice', 'typeId' => 1, 'year' => 2026, 'data' => ['baseDays' => 28.0], 'line' => 2]]);
		$service->method('apply')->willThrowException(new ValidationException('Unknown employee.'));

		$tester = $this->execute(new ImportEntitlements($service));

		self::assertSame(1, $tester->getStatusCode());
		self::assertStringContainsString('Unknown employee.', $tester->getDisplay());
	}

	public function testLeaveIsImportedWithoutAnActor(): void {
		$preview = ['entries' => 1, 'days' => 1.0, 'skipped' => 0, 'people' => [], 'errors' => []];
		$service = $this->createMock(LeaveImportService::class);
		$service->method('preview')->willReturn($preview);
		$service->expects(self::once())->method('import')->with(self::anything(), null)->willReturn($preview);

		self::assertSame(0, $this->execute(new ImportLeave($service))->getStatusCode());
	}

	public function testARefusedLeaveImportExitsWithItsMessage(): void {
		$service = $this->createMock(LeaveImportService::class);
		$service->method('preview')->willReturn(['entries' => 1, 'days' => 1.0, 'skipped' => 0, 'people' => [], 'errors' => []]);
		$service->method('import')->willThrowException(new ConflictException('Another change is being processed.'));

		$tester = $this->execute(new ImportLeave($service));

		self::assertSame(1, $tester->getStatusCode());
		self::assertStringContainsString('Another change is being processed.', $tester->getDisplay());
	}

	public function testARefusedEmployeesPlanExitsWithItsMessage(): void {
		$service = $this->createMock(EmployeeJsonService::class);
		$service->method('plan')->willThrowException(new ValidationException('There is no annual leave type to set vacation days for'));

		$tester = $this->execute(new ImportEmployees($service));

		self::assertSame(1, $tester->getStatusCode());
		self::assertStringContainsString('There is no annual leave type', $tester->getDisplay());
	}

	private function execute(Command $command): CommandTester {
		$tester = new CommandTester($command);
		$tester->execute(['file' => $this->file]);
		return $tester;
	}
}
