<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\InsightsService;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Service\RequestService;
use OCP\Config\IUserConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * User ids made of digits, as some LDAP setups have, become integers as array
 * keys in PHP; the team and insight views must still work for them.
 */
class NumericUidTest extends TestCase {
	private const EMPLOYEE = '90418';
	private const MANAGER = 'itest-numeric-mgr';
	private const ZERO = '0';

	/** @var LeaveRequest[] */
	private array $requests = [];

	protected function setUp(): void {
		parent::setUp();
		$users = Server::get(IUserManager::class);
		$users->createUser(self::MANAGER, 'Itest-Password-numeric-1');
		$users->createUser(self::EMPLOYEE, 'Itest-Password-numeric-2')->setManagerUids([self::MANAGER]);
	}

	protected function tearDown(): void {
		foreach ($this->requests as $request) {
			Server::get(LeaveRequestMapper::class)->delete($request);
		}
		foreach ([self::EMPLOYEE, self::MANAGER] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		parent::tearDown();
	}

	public function testTheTeamOfANumericUser(): void {
		// A fresh resolver, as each request gets one: the shared one keeps who
		// reports to whom from the tests that ran before
		$resolver = new ManagerResolver(Server::get(IUserManager::class), Server::get(IUserConfig::class),
			new EmployeeDirectory(Server::get(IUserManager::class), Server::get(IGroupManager::class), Server::get(ConfigService::class), Server::get(LoggerInterface::class)),
			Server::get(LoggerInterface::class));

		self::assertSame([self::EMPLOYEE], $resolver->getDirectReports(self::MANAGER));
	}

	public function testInsightsWithANumericUser(): void {
		$insights = Server::get(InsightsService::class)->getInsights((int)date('Y'));

		self::assertContains(self::EMPLOYEE, array_column($insights['utilization']['neglected'], 'employeeUid'));
	}

	public function testTheUserZeroListsOnlyTheirOwnRequests(): void {
		$mine = $this->seedRequest(self::ZERO, self::MANAGER);
		$this->seedRequest(self::EMPLOYEE, self::MANAGER);
		$reporting = $this->seedRequest(self::EMPLOYEE, self::ZERO);
		$service = Server::get(RequestService::class);

		self::assertSame([$mine->getId()], array_map(static fn (LeaveRequest $r): int => $r->getId(), $service->list(self::ZERO, [], null, null)));
		self::assertSame([$reporting->getId()], array_map(static fn (LeaveRequest $r): int => $r->getId(), $service->list(self::ZERO, ['scope' => 'reports'], null, null)));
	}

	private function seedRequest(string $employeeUid, string $managerUid): LeaveRequest {
		$request = new LeaveRequest();
		$request->setEmployeeUid($employeeUid);
		$request->setManagerUid($managerUid);
		$request->setTypeId(1);
		$request->setStartDate('2027-03-01');
		$request->setEndDate('2027-03-02');
		$request->setWorkingDays(2.0);
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		$request->setCreatedAt(new \DateTime('2027-01-04 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2027-01-04 09:00:00'));
		return $this->requests[] = Server::get(LeaveRequestMapper::class)->insert($request);
	}
}
