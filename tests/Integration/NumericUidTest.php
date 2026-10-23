<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Service\InsightsService;
use OCA\Absence\Service\ManagerResolver;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * User ids made of digits, as some LDAP setups have, become integers as array
 * keys in PHP; the team and insight views must still work for them.
 */
class NumericUidTest extends TestCase {
	private const EMPLOYEE = '90418';
	private const MANAGER = 'itest-numeric-mgr';

	protected function setUp(): void {
		parent::setUp();
		$users = Server::get(IUserManager::class);
		$users->createUser(self::MANAGER, 'Itest-Password-numeric-1');
		$users->createUser(self::EMPLOYEE, 'Itest-Password-numeric-2')->setManagerUids([self::MANAGER]);
	}

	protected function tearDown(): void {
		foreach ([self::EMPLOYEE, self::MANAGER] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		parent::tearDown();
	}

	public function testTheTeamOfANumericUser(): void {
		self::assertSame([self::EMPLOYEE], Server::get(ManagerResolver::class)->getDirectReports(self::MANAGER));
	}

	public function testInsightsWithANumericUser(): void {
		$insights = Server::get(InsightsService::class)->getInsights((int)date('Y'));

		self::assertContains(self::EMPLOYEE, array_column($insights['utilization']['neglected'], 'employeeUid'));
	}
}
