<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\EmployeeListService;
use OCA\Absence\Service\InsightsService;
use OCA\Absence\Service\ReportService;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A disabled account is somebody who left (§2.2): HR keeps their records, and
 * nothing about the people working here now counts them.
 */
class DisabledAccountsTest extends TestCase {
	private const YEAR = 2026;
	private const ACTIVE = 'itest-disabled-active';
	private const LEFT = 'itest-disabled-left';

	protected function setUp(): void {
		parent::setUp();
		$users = Server::get(IUserManager::class);
		foreach ([self::ACTIVE, self::LEFT] as $uid) {
			($users->get($uid) ?? $users->createUser($uid, 'Itest-Password-disabled-1'))->setDisplayName('Itest ' . $uid);
		}
		$users->get(self::LEFT)->setEnabled(false);
	}

	protected function tearDown(): void {
		foreach ([self::ACTIVE, self::LEFT] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		parent::tearDown();
	}

	public function testTheDirectoryKeepsThemAsEmployeesButNotAsActiveOnes(): void {
		$directory = Server::get(EmployeeDirectory::class);

		self::assertTrue($directory->isEmployee(self::LEFT));
		self::assertContains(self::LEFT, $directory->listAll());
		self::assertArrayHasKey(self::LEFT, $directory->displayNames());

		self::assertFalse($directory->isActive(self::LEFT));
		self::assertTrue($directory->isDisabled(self::LEFT));
		self::assertNotContains(self::LEFT, $directory->listActive());
		self::assertNotContains(self::LEFT, $directory->listActiveInGroup(null));
		self::assertArrayNotHasKey(self::LEFT, $directory->activeDisplayNames());
		self::assertSame([self::ACTIVE], $directory->filterActive([self::LEFT, self::ACTIVE]));

		self::assertTrue($directory->isActive(self::ACTIVE));
		self::assertContains(self::ACTIVE, $directory->listActive());
	}

	public function testHrStillSeesThemWithNothingMissing(): void {
		$rows = Server::get(EmployeeListService::class)->rows([self::ACTIVE, self::LEFT], self::YEAR);
		[$active, $left] = $rows;

		self::assertTrue($left['disabled']);
		self::assertSame([], $left['missing']);
		self::assertFalse($left['regionIncomplete']);

		self::assertFalse($active['disabled']);
		self::assertNotSame([], $active['missing']);
	}

	public function testReportsAndInsightsLeaveThemOut(): void {
		$report = Server::get(ReportService::class)->balancesReport(self::YEAR);
		self::assertNotContains(self::LEFT, array_column($report, 'employeeUid'));
		self::assertContains(self::ACTIVE, array_column($report, 'employeeUid'));

		$insights = json_encode(Server::get(InsightsService::class)->getInsights(self::YEAR), JSON_THROW_ON_ERROR);
		self::assertStringNotContainsString(self::LEFT, $insights);
	}
}
