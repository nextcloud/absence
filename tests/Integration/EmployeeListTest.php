<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OC\AppFramework\DependencyInjection\DIContainer;
use OC\DB\Connection;
use OCA\Absence\ConfigLexicon;
use OCA\Absence\Controller\EmployeeController;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\EmployeeListService;
use OCA\Absence\Service\EmployeeService;
use OCA\Absence\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\Config\IUserConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * HR's employees list (§13), against a real server.
 */
class EmployeeListTest extends TestCase {
	private const YEAR = 2026;
	private const HR = 'itest-emplist-hr';
	private const MANAGER = 'itest-emplist-mgr';
	private const PART_TIME = 'itest-emplist-a';
	private const BARE = 'itest-emplist-b';

	/** @var list<string> */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();
		foreach ([self::HR, self::MANAGER, self::PART_TIME, self::BARE] as $uid) {
			$this->createUser($uid);
		}
		$groups = Server::get(IGroupManager::class);
		($groups->get('hr') ?? $groups->createGroup('hr'))->addUser(Server::get(IUserManager::class)->get(self::HR));
		$this->fill(self::PART_TIME);
	}

	protected function tearDown(): void {
		foreach ($this->created as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		parent::tearDown();
	}

	public function testOnlyHrSeesTheList(): void {
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(self::PART_TIME)->index(self::YEAR)->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(self::MANAGER)->index(self::YEAR)->getStatus());

		$response = $this->controller(self::HR)->index(self::YEAR);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertContains(self::PART_TIME, array_column($response->getData()['rows'], 'uid'));
	}

	public function testARowCarriesWhatTheProfileAndTheBalanceSay(): void {
		$row = $this->row(self::PART_TIME);

		self::assertSame('DE-BY', $row['regionCode']);
		self::assertSame(['uid' => self::MANAGER, 'displayName' => 'Itest ' . self::MANAGER], $row['manager']);
		self::assertSame(['from' => '2026-03-16', 'weekdays' => [1, 2, 3, 4], 'percent' => 80], $row['current']);
		self::assertSame('2026-03-16', $row['contract']['start']);
		self::assertSame(2.0, $row['sickDays']);

		$single = null;
		foreach ($this->fresh(BalanceService::class)->getBalance(self::PART_TIME, self::YEAR)['balances'] as $balance) {
			if ($balance['typeKey'] === 'annual') {
				$single = $balance;
			}
		}
		self::assertSame(3.0, $row['annual']['used']);
		self::assertSame($row['annual']['entitlement'] - 3.0, $row['annual']['remaining']);
		foreach (['entitlement', 'used', 'remaining', 'entitlementId'] as $key) {
			self::assertSame($single[$key], $row['annual'][$key], $key);
		}
	}

	public function testRowsSayWhatIsMissingAndWhoWorksPartTime(): void {
		$filled = $this->row(self::PART_TIME);
		self::assertSame([], $filled['missing']);
		self::assertTrue($filled['partTime']);
		self::assertTrue($filled['startsOrLeaves']);
		self::assertFalse($this->row(self::PART_TIME, self::YEAR + 1)['startsOrLeaves']);

		$bare = $this->row(self::BARE);
		self::assertSame([EmployeeListService::MISSING_REGION, EmployeeListService::MISSING_MANAGER, EmployeeListService::MISSING_CONTRACT], $bare['missing']);
		self::assertFalse($bare['partTime']);
		self::assertNull($bare['manager']);

		// Five days at full time from the start, ending next year
		$this->contract(self::BARE, [1, 2, 3, 4, 5], 100);
		$bare = $this->row(self::BARE);
		self::assertNotContains(EmployeeListService::MISSING_CONTRACT, $bare['missing']);
		self::assertFalse($bare['partTime']);
		self::assertFalse($bare['startsOrLeaves']);
		self::assertTrue($this->row(self::BARE, self::YEAR + 1)['startsOrLeaves']);

		$this->contract(self::BARE, [1, 2, 3, 4], 100);
		self::assertTrue($this->row(self::BARE)['partTime'], 'four days a week');
		$this->contract(self::BARE, [1, 2, 3, 4, 5], 50);
		self::assertTrue($this->row(self::BARE)['partTime'], 'five days at 50%');
		$this->contract(self::BARE, [1, 2, 3, 4, 5], null);
		self::assertFalse($this->row(self::BARE)['partTime'], 'five days, no percentage');
	}

	/**
	 * @param list<int> $weekdays
	 */
	private function contract(string $uid, array $weekdays, ?int $percent): void {
		$this->fresh(EmployeeService::class)->saveContract($uid, '2020-01-01', '2027-06-30', [
			['from' => '2020-01-01', 'weekdays' => $weekdays, 'percent' => $percent],
		], self::HR);
	}

	public function testTheListCostsTheSameNumberOfQueriesForMorePeople(): void {
		$few = [self::MANAGER, self::PART_TIME, self::BARE];
		$this->queries($few);
		$before = $this->queries($few);

		$more = $few;
		foreach (['itest-emplist-c', 'itest-emplist-d', 'itest-emplist-e'] as $uid) {
			$this->createUser($uid);
			$this->fill($uid);
			$more[] = $uid;
		}

		self::assertSame($before, $this->queries($more));
	}

	/**
	 * Region code, manager, a contract, three days of annual leave and two sick days.
	 */
	private function fill(string $uid): void {
		$config = Server::get(ConfigService::class);
		$config->setPersonalValue($uid, ConfigLexicon::KEY_HOLIDAY_COUNTRY, 'DE');
		$config->setPersonalValue($uid, ConfigLexicon::KEY_HOLIDAY_REGION, 'BY');
		Server::get(IUserManager::class)->get($uid)->setManagerUids([self::MANAGER]);
		$this->fresh(EmployeeService::class)->saveContract($uid, '2026-03-16', null, [
			['from' => '2026-03-16', 'weekdays' => [1, 2, 3, 4], 'percent' => 80],
		], self::HR);
		$this->leave($uid, 'annual', '2026-06-01', '2026-06-03', 3.0);
		$this->leave($uid, 'sick', '2026-05-04', '2026-05-05', 2.0);
		$this->leave($uid, 'sick', '2025-05-04', '2025-05-05', 2.0);
	}

	private function leave(string $uid, string $typeKey, string $start, string $end, float $days): void {
		$typeId = null;
		foreach (Server::get(LeaveTypeMapper::class)->findAll() as $type) {
			if ($type->getKey() === $typeKey) {
				$typeId = $type->getId();
			}
		}
		$request = new LeaveRequest();
		$request->setEmployeeUid($uid);
		$request->setTypeId((int)$typeId);
		$request->setStartDate($start);
		$request->setEndDate($end);
		$request->setWorkingDays($days);
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		$request->setCreatedAt(new \DateTime('2026-01-01 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2026-01-01 09:00:00'));
		Server::get(LeaveRequestMapper::class)->insert($request);
	}

	/**
	 * Queries the list of these people takes, from services as fresh as in a new
	 * HTTP request.
	 *
	 * @param list<string> $uids
	 */
	private function queries(array $uids): int {
		$list = $this->fresh(EmployeeListService::class);
		Server::get(IUserConfig::class)->clearCacheAll();
		$connection = Server::get(Connection::class);
		$before = $connection->getStats()['executed'];
		$list->rows($uids, self::YEAR);
		return $connection->getStats()['executed'] - $before;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row(string $uid, int $year = self::YEAR): array {
		$rows = $this->fresh(EmployeeListService::class)->rows([$uid], $year);
		self::assertCount(1, $rows);
		return $rows[0];
	}

	/**
	 * A service without what an earlier test left in the shared instances' caches.
	 *
	 * @template T
	 * @param class-string<T> $class
	 * @return T
	 */
	private function fresh(string $class): object {
		// A new container registers itself as the app's: put the app's own back
		$server = \OC::$server;
		$registered = $server->getRegisteredAppContainer('absence');
		$service = (new DIContainer('absence'))->get($class);
		$server->registerAppContainer('absence', $registered);
		return $service;
	}

	private function createUser(string $uid): void {
		$user = Server::get(IUserManager::class)->createUser($uid, 'Itest-Password-' . strlen($uid) . 'x');
		$user->setDisplayName('Itest ' . $uid);
		$this->created[] = $uid;
	}

	private function controller(string $uid): EmployeeController {
		Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
		return new EmployeeController('absence', Server::get(IRequest::class), $uid, Server::get(IUserManager::class),
			Server::get(IGroupManager::class), Server::get(ISearch::class), Server::get(EmployeeDirectory::class),
			Server::get(EmployeeListService::class), Server::get(PermissionService::class), Server::get(IFactory::class)->get('absence'));
	}
}
