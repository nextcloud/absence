<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\CoverageService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Service\PermissionService;
use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The replacement picker (§5.1) against a real server: the teammates it offers
 * before anything is typed, and whether the chosen replacement is away.
 */
class ReplacementPickerTest extends TestCase {
	private const PREFIX = 'itest-replace-';
	private const STAFF_GROUP = 'itest-replace-staff';
	private const HR_GROUP = 'itest-replace-hr';
	/** Alice, Bob and Charlie report to Dave, Dave to Erin; Eve reports to Alice */
	private const ALICE = self::PREFIX . 'alice';
	private const BOB = self::PREFIX . 'bob';
	private const CHARLIE = self::PREFIX . 'charlie';
	private const DAVE = self::PREFIX . 'dave';
	private const ERIN = self::PREFIX . 'erin';
	private const EVE = self::PREFIX . 'eve';
	/** Reports to Dave too, but is outside the employees group */
	private const FRANK = self::PREFIX . 'frank';
	private const HR = self::PREFIX . 'hr';
	private const USERS = [self::ALICE, self::BOB, self::CHARLIE, self::DAVE, self::ERIN, self::EVE, self::FRANK, self::HR];

	/** @var array<string,string> */
	private array $configBefore = [];
	private bool $sharedCalendarBefore = false;

	protected function setUp(): void {
		parent::setUp();
		$users = Server::get(IUserManager::class);
		$groups = Server::get(IGroupManager::class);
		$staff = $groups->createGroup(self::STAFF_GROUP);
		foreach (self::USERS as $uid) {
			$user = $users->createUser($uid, 'Itest-Password-' . $uid);
			$user->setDisplayName(ucfirst(substr($uid, strlen(self::PREFIX))));
			if ($uid !== self::FRANK) {
				$staff->addUser($user);
			}
		}
		$groups->createGroup(self::HR_GROUP)->addUser($users->get(self::HR));
		foreach ([self::ALICE, self::BOB, self::CHARLIE, self::FRANK] as $uid) {
			$users->get($uid)->setManagerUids([self::DAVE]);
		}
		$users->get(self::DAVE)->setManagerUids([self::ERIN]);
		$users->get(self::EVE)->setManagerUids([self::ALICE]);

		$config = Server::get(ConfigService::class);
		$this->configBefore = [
			ConfigLexicon::KEY_HR_GROUP => $config->getHrGroup(),
			ConfigLexicon::KEY_EMPLOYEES_GROUP => $config->getEmployeesGroup(),
		];
		$this->sharedCalendarBefore = $config->isCalDavSharedEnabled();
		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_SHARED, false);
		$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, self::HR_GROUP);
		$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_EMPLOYEES_GROUP, self::STAFF_GROUP);
	}

	protected function tearDown(): void {
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete('absence_requests')
			->where($qb->expr()->in('employee_uid', $qb->createNamedParameter(self::USERS, IQueryBuilder::PARAM_STR_ARRAY)))
			->executeStatement();
		foreach (self::USERS as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		foreach ([self::STAFF_GROUP, self::HR_GROUP] as $gid) {
			Server::get(IGroupManager::class)->get($gid)?->delete();
		}
		foreach ($this->configBefore as $key => $value) {
			Server::get(IAppConfig::class)->setValueString(ConfigService::APP_ID, $key, $value);
		}
		Server::get(IAppConfig::class)->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_SHARED, $this->sharedCalendarBefore);
		parent::tearDown();
	}

	public function testTeammatesAreThePeersTheManagerAndTheReports(): void {
		self::assertEqualsCanonicalizing([self::BOB, self::CHARLIE, self::DAVE, self::EVE], $this->uids($this->service()->getTeammates(self::ALICE, self::ALICE)));
	}

	public function testAManagersTeammatesAreTheirReportsAndTheirOwnManager(): void {
		// Frank reports to Dave but is no employee, so he is nobody's replacement.
		self::assertEqualsCanonicalizing([self::ALICE, self::BOB, self::CHARLIE, self::ERIN], $this->uids($this->service()->getTeammates(self::DAVE, self::DAVE)));
	}

	public function testTeammatesComeWithTheirName(): void {
		$byUid = array_column($this->service()->getTeammates(self::EVE, self::EVE), 'displayName', 'uid');

		self::assertSame([self::ALICE => 'Alice'], $byUid);
	}

	public function testHrAsksForAnyEmployee(): void {
		self::assertEqualsCanonicalizing([self::ALICE, self::BOB, self::DAVE], $this->uids($this->service()->getTeammates(self::HR, self::CHARLIE)));
	}

	public function testAnEmployeeOnlyAsksForThemselves(): void {
		$this->expectException(ForbiddenException::class);
		$this->service()->getTeammates(self::DAVE, self::ALICE);
	}

	public function testSomebodyOutsideTheEmployeesHasNoTeammatesToAskFor(): void {
		$this->expectException(ForbiddenException::class);
		$this->service()->getTeammates(self::FRANK, self::FRANK);
	}

	public function testLeaveInForceOrAskedForIsAway(): void {
		foreach ([LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_ESCALATED, LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_WITHDRAWAL_PENDING] as $status) {
			$request = $this->seed(self::BOB, $status, '2027-03-08', '2027-03-10');
			self::assertTrue($this->service()->isAway(self::ALICE, self::BOB, '2027-03-10', '2027-03-12'), $status);
			Server::get(LeaveRequestMapper::class)->delete($request);
		}
	}

	public function testLeaveThatIsNotTakenOrOtherDaysIsNotAway(): void {
		$this->seed(self::BOB, LeaveRequest::STATUS_REJECTED, '2027-03-08', '2027-03-10');
		$this->seed(self::BOB, LeaveRequest::STATUS_CANCELLED, '2027-03-08', '2027-03-10');
		$this->seed(self::BOB, LeaveRequest::STATUS_APPROVED, '2027-03-01', '2027-03-05');

		self::assertFalse($this->service()->isAway(self::ALICE, self::BOB, '2027-03-08', '2027-03-12'));
	}

	public function testHrAndTheTeamGetAnAnswer(): void {
		$this->seed(self::BOB, LeaveRequest::STATUS_APPROVED, '2027-03-08', '2027-03-10');

		self::assertTrue($this->service()->isAway(self::HR, self::BOB, '2027-03-08', '2027-03-12'));
		// Dave is Bob's manager
		self::assertTrue($this->service()->isAway(self::DAVE, self::BOB, '2027-03-08', '2027-03-12'));
	}

	public function testSomebodyWithNoTieToThePersonGetsNoAnswer(): void {
		// Eve reports to Alice: Bob is not one of her teammates.
		$this->seed(self::BOB, LeaveRequest::STATUS_APPROVED, '2027-03-08', '2027-03-10');

		self::assertNull($this->service()->isAway(self::EVE, self::BOB, '2027-03-08', '2027-03-12'));
	}

	public function testEveryoneGetsAnAnswerWhileTheSharedCalendarShowsEveryone(): void {
		Server::get(IAppConfig::class)->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_SHARED, true);
		$this->seed(self::BOB, LeaveRequest::STATUS_APPROVED, '2027-03-08', '2027-03-10');

		self::assertTrue($this->service()->isAway(self::EVE, self::BOB, '2027-03-08', '2027-03-12'));
	}

	public function testTheSharedCalendarOnlyTellsOfLeaveInForce(): void {
		Server::get(IAppConfig::class)->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_SHARED, true);
		$this->seed(self::BOB, LeaveRequest::STATUS_PENDING, '2027-03-08', '2027-03-10');
		$this->seed(self::BOB, LeaveRequest::STATUS_WITHDRAWAL_PENDING, '2027-04-08', '2027-04-10');

		self::assertFalse($this->service()->isAway(self::EVE, self::BOB, '2027-03-08', '2027-03-12'));
		self::assertTrue($this->service()->isAway(self::EVE, self::BOB, '2027-04-08', '2027-04-12'));
		// Alice is Bob's teammate
		self::assertTrue($this->service()->isAway(self::ALICE, self::BOB, '2027-03-08', '2027-03-12'));
	}

	public function testNoSharedCalendarUntilTheAppIsSetUp(): void {
		Server::get(IAppConfig::class)->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_SHARED, true);
		Server::get(IAppConfig::class)->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, '');
		$this->seed(self::BOB, LeaveRequest::STATUS_APPROVED, '2027-03-08', '2027-03-10');

		self::assertNull($this->service()->isAway(self::EVE, self::BOB, '2027-03-08', '2027-03-12'));
	}

	public function testARangeOverAYearIsRefused(): void {
		$this->expectException(ValidationException::class);
		$this->service()->isAway(self::ALICE, self::BOB, '2027-01-01', '2028-01-03');
	}

	public function testOnlyEmployeesAndHrAskWhoIsAway(): void {
		$this->expectException(ForbiddenException::class);
		$this->service()->isAway(self::FRANK, self::BOB, '2027-03-08', '2027-03-12');
	}

	public function testSomebodyWhoIsNoEmployeeGetsNoAnswer(): void {
		$this->seed(self::FRANK, LeaveRequest::STATUS_APPROVED, '2027-03-08', '2027-03-10');

		self::assertNull($this->service()->isAway(self::HR, self::FRANK, '2027-03-08', '2027-03-12'));
		self::assertNull($this->service()->isAway(self::HR, self::PREFIX . 'nobody', '2027-03-08', '2027-03-12'));
	}

	/**
	 * The resolver and the directory remember what they read for the length of
	 * a web request, so each test builds its own.
	 */
	private function service(): CoverageService {
		$directory = new EmployeeDirectory(Server::get(IUserManager::class), Server::get(IGroupManager::class), Server::get(ConfigService::class), Server::get(LoggerInterface::class));
		$resolver = new ManagerResolver(Server::get(IUserManager::class), Server::get(IUserConfig::class), $directory, Server::get(LoggerInterface::class));
		$l = Server::get(IFactory::class)->get(ConfigService::APP_ID, 'en');
		return new CoverageService(
			Server::get(LeaveRequestMapper::class),
			Server::get(LeaveTypeMapper::class),
			$resolver,
			new PermissionService(Server::get(IGroupManager::class), $resolver, Server::get(ConfigService::class), Server::get(LeaveTypeMapper::class), $l, Server::get(LoggerInterface::class), $directory),
			Server::get(ConfigService::class),
			$directory,
			Server::get(IUserManager::class),
			$l,
		);
	}

	/**
	 * @param list<array{uid: string, displayName: string}> $people
	 * @return list<string>
	 */
	private function uids(array $people): array {
		return array_column($people, 'uid');
	}

	private function seed(string $uid, string $status, string $start, string $end): LeaveRequest {
		$request = new LeaveRequest();
		$request->setEmployeeUid($uid);
		$request->setTypeId(1);
		$request->setStartDate($start);
		$request->setEndDate($end);
		$request->setWorkingDays(1.0);
		$request->setStatus($status);
		$request->setCreatedAt(new \DateTime());
		$request->setUpdatedAt(new \DateTime());
		return Server::get(LeaveRequestMapper::class)->insert($request);
	}
}
