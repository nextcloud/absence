<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Controller\CalendarController;
use OCA\Absence\Controller\CoverageController;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\CoverageService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\HolidayService;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Service\PermissionService;
use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who is on a team's timeline and who counts toward its coverage (§8), against
 * a real server: an employee also sees their own manager on the timeline, the
 * coverage counts stay with the team the manager decides for.
 */
class TeamScopeTest extends TestCase {
	private const BOSS = 'itest-team-boss';
	private const MANAGER = 'itest-team-manager';
	private const ALICE = 'itest-team-alice';
	private const BOB = 'itest-team-bob';
	private const USERS = [self::BOSS, self::MANAGER, self::ALICE, self::BOB];
	private const TYPE_KEY = 'itest-team';

	private int $typeId = 0;
	private string $visibilityBefore = '';

	protected function setUp(): void {
		parent::setUp();
		$users = Server::get(IUserManager::class);
		foreach (self::USERS as $uid) {
			$users->createUser($uid, 'Itest-Password-team-' . $uid);
		}
		$users->get(self::MANAGER)->setManagerUids([self::BOSS]);
		$users->get(self::ALICE)->setManagerUids([self::MANAGER]);
		$users->get(self::BOB)->setManagerUids([self::MANAGER]);

		$this->visibilityBefore = Server::get(ConfigService::class)->getSharedCalendarVisibility();
		Server::get(IAppConfig::class)->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_SHARED_VISIBILITY, ConfigService::VISIBILITY_NEUTRAL);

		$type = new LeaveType();
		$type->setKey(self::TYPE_KEY);
		$type->setLabel('itest team');
		$type->setCountsAgainstBalance(false);
		$this->typeId = Server::get(LeaveTypeMapper::class)->insert($type)->getId();

		$this->seed(self::BOSS, null);
		$this->seed(self::MANAGER, self::BOSS);
		$this->seed(self::BOB, self::MANAGER);
	}

	protected function tearDown(): void {
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete('absence_requests')
			->where($qb->expr()->in('employee_uid', $qb->createNamedParameter(self::USERS, IQueryBuilder::PARAM_STR_ARRAY)))
			->executeStatement();
		$qb = $db->getQueryBuilder();
		$qb->delete('absence_leave_types')->where($qb->expr()->eq('key', $qb->createNamedParameter(self::TYPE_KEY)))->executeStatement();
		foreach (self::USERS as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		Server::get(IAppConfig::class)->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_SHARED_VISIBILITY, $this->visibilityBefore);
		parent::tearDown();
	}

	public function testAnEmployeeSeesTheirManagerOnTheTeamTimeline(): void {
		$events = $this->timelineOf(self::ALICE);

		self::assertSame([self::BOB, self::MANAGER], $this->people($events));
		$managerEvent = array_values(array_filter($events, static fn (array $event): bool => $event['employeeUid'] === self::MANAGER))[0];
		self::assertNull($managerEvent['typeId'], 'the manager\'s leave type follows the neutral policy');
	}

	public function testAManagerStillSeesTheirReportsAndNotTheirOwnManager(): void {
		self::assertSame([self::BOB, self::MANAGER], $this->people($this->timelineOf(self::MANAGER)));
	}

	public function testTheManagerDoesNotCountTowardTheTeamCoverage(): void {
		$coverage = $this->coverageOf(self::ALICE);

		self::assertSame([self::BOB], $this->people($coverage['events']));
		self::assertSame(1, $coverage['maxConcurrent']);
	}

	private function seed(string $employeeUid, ?string $managerUid): void {
		$request = new LeaveRequest();
		$request->setEmployeeUid($employeeUid);
		$request->setManagerUid($managerUid);
		$request->setTypeId($this->typeId);
		$request->setStartDate('2027-06-07');
		$request->setEndDate('2027-06-11');
		$request->setWorkingDays(5.0);
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		$request->setCreatedAt(new \DateTime('-1 day'));
		$request->setUpdatedAt(new \DateTime('-1 day'));
		Server::get(LeaveRequestMapper::class)->insert($request);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function timelineOf(string $uid): array {
		$controller = new CalendarController('absence', Server::get(IRequest::class), $uid, $this->coverage(),
			Server::get(HolidayService::class), Server::get(IFactory::class)->get('absence'), Server::get(LoggerInterface::class));
		return $controller->index('2027-06-01', '2027-06-30')->getData()['events'];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function coverageOf(string $uid): array {
		$controller = new CoverageController('absence', Server::get(IRequest::class), $uid, $this->coverage(),
			Server::get(IFactory::class)->get('absence'), Server::get(LoggerInterface::class));
		return $controller->index('2027-06-01', '2027-06-30')->getData();
	}

	/**
	 * @param list<array<string,mixed>> $events
	 * @return list<string> the people with an event, sorted
	 */
	private function people(array $events): array {
		$uids = array_values(array_unique(array_column($events, 'employeeUid')));
		sort($uids);
		return $uids;
	}

	/**
	 * A fresh service: the resolver and the directory remember what they read
	 * for the length of a web request.
	 */
	private function coverage(): CoverageService {
		$directory = new EmployeeDirectory(Server::get(IUserManager::class), Server::get(IGroupManager::class), Server::get(ConfigService::class), Server::get(LoggerInterface::class));
		$resolver = new ManagerResolver(Server::get(IUserManager::class), Server::get(IUserConfig::class), $directory, Server::get(LoggerInterface::class));
		$l = Server::get(IFactory::class)->get('absence');
		$permission = new PermissionService(Server::get(IGroupManager::class), $resolver, Server::get(ConfigService::class),
			Server::get(LeaveTypeMapper::class), $l, Server::get(LoggerInterface::class), $directory);
		return new CoverageService(Server::get(LeaveRequestMapper::class), Server::get(LeaveTypeMapper::class), $resolver, $permission,
			Server::get(ConfigService::class), $directory, Server::get(IUserManager::class), $l);
	}
}
