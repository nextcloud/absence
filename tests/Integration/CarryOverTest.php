<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\Entitlement;
use OCA\Absence\Db\EntitlementEvent;
use OCA\Absence\Db\EntitlementEventMapper;
use OCA\Absence\Db\EntitlementMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\ActivityPublisher;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\EntitlementService;
use OCA\Absence\Service\EntitlementValidator;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The year rollover and the carry-over expiry (§6.2) on a real database:
 * 2031 rolls over into 2032, and carry-over expires on 2032-03-31.
 */
class CarryOverTest extends TestCase {
	private const GROUP = 'itest-carry-employees';
	private const WITH_ROW = 'itest-carry-row';
	private const ON_DEFAULT = 'itest-carry-default';
	private const HR = 'itest-carry-hr';

	/** @var array<string,mixed> */
	private array $snapshot = [];
	private int $annual = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->snapshot = Server::get(ConfigService::class)->getAdminConfig();
		$group = Server::get(IGroupManager::class)->createGroup(self::GROUP);
		foreach ([self::WITH_ROW, self::ON_DEFAULT] as $uid) {
			$group->addUser(Server::get(IUserManager::class)->createUser($uid, 'itest-' . bin2hex(random_bytes(8))));
		}
		$appConfig = Server::get(IAppConfig::class);
		// Only the two test employees are rolled over, whatever else the instance holds.
		$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_EMPLOYEES_GROUP, self::GROUP);
		$appConfig->setValueFloat(ConfigService::APP_ID, ConfigLexicon::KEY_DEFAULT_ENTITLEMENT, 28.0);
		$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_CARRYOVER_POLICY, ConfigService::CARRYOVER_CAPPED);
		$appConfig->setValueFloat(ConfigService::APP_ID, ConfigLexicon::KEY_CARRYOVER_CAP, 5.0);
		$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_CARRYOVER_EXPIRY, '03-31');
		foreach (Server::get(LeaveTypeMapper::class)->findAll() as $type) {
			if ($type->getKey() === 'annual') {
				$this->annual = (int)$type->getId();
			}
		}
	}

	protected function tearDown(): void {
		$appConfig = Server::get(IAppConfig::class);
		foreach ($this->snapshot as $key => $value) {
			$appConfig->deleteKey(ConfigService::APP_ID, $key);
			match (true) {
				is_bool($value) => $appConfig->setValueBool(ConfigService::APP_ID, $key, $value),
				is_int($value) => $appConfig->setValueInt(ConfigService::APP_ID, $key, $value),
				is_float($value) => $appConfig->setValueFloat(ConfigService::APP_ID, $key, $value),
				default => $appConfig->setValueString(ConfigService::APP_ID, $key, (string)$value),
			};
		}
		$db = Server::get(IDBConnection::class);
		foreach (['absence_requests', 'absence_entitlements', 'absence_entitlement_events'] as $table) {
			foreach ([self::WITH_ROW, self::ON_DEFAULT] as $uid) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($uid)))->executeStatement();
			}
		}
		foreach ([self::WITH_ROW, self::ON_DEFAULT] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		Server::get(IGroupManager::class)->get(self::GROUP)?->delete();
		parent::tearDown();
	}

	/** The service as the background job runs it on the given day. */
	private function service(string $today = '2032-01-01'): EntitlementService {
		$clock = $this->createMock(ClockService::class);
		$clock->method('serverToday')->willReturn($today);
		$clock->method('now')->willReturnCallback(static fn (): \DateTime => new \DateTime('now', new \DateTimeZone('UTC')));
		$config = Server::get(ConfigService::class);
		$l = Server::get(IFactory::class)->get(ConfigService::APP_ID);
		return new EntitlementService(
			Server::get(EntitlementMapper::class),
			Server::get(EntitlementEventMapper::class),
			Server::get(LeaveRequestMapper::class),
			Server::get(LeaveTypeMapper::class),
			Server::get(BalanceService::class),
			$config,
			$clock,
			Server::get(ActivityPublisher::class),
			// a fresh one: the shared instance remembers the employees group
			new EmployeeDirectory(Server::get(IUserManager::class), Server::get(IGroupManager::class), $config, Server::get(LoggerInterface::class)),
			new EntitlementValidator($l),
			Server::get(IDBConnection::class),
			$l,
			Server::get(LoggerInterface::class),
			Server::get(IEventDispatcher::class),
		);
	}

	private function leave(string $uid, string $status, string $start, float $days): void {
		$request = new LeaveRequest();
		$request->setEmployeeUid($uid);
		$request->setTypeId($this->annual);
		$request->setStartDate($start);
		$request->setEndDate($start);
		$request->setWorkingDays($days);
		$request->setStatus($status);
		$request->setCreatedAt(new \DateTime('2031-01-01 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2031-01-01 09:00:00'));
		Server::get(LeaveRequestMapper::class)->insert($request);
	}

	private function entitlement(string $uid, int $year, float $base, float $carry = 0.0): Entitlement {
		$ent = new Entitlement();
		$ent->setEmployeeUid($uid);
		$ent->setYear($year);
		$ent->setTypeId($this->annual);
		$ent->setBaseDays($base);
		$ent->setCarryOverDays($carry);
		$ent->setManualAdjustment(0.0);
		$ent->setCreatedAt(new \DateTime('2031-01-01 09:00:00'));
		$ent->setUpdatedAt(new \DateTime('2031-01-01 09:00:00'));
		return Server::get(EntitlementMapper::class)->insert($ent);
	}

	private function stored(string $uid, int $year): Entitlement {
		return Server::get(EntitlementMapper::class)->findFor($uid, $year, $this->annual);
	}

	/**
	 * @return list<array{string,string,float,float,?string}> actor, field, old, new, note
	 */
	private function history(Entitlement $ent): array {
		return array_map(
			static fn (EntitlementEvent $e): array => [$e->getActorUid(), $e->getField(), $e->getOldValue(), $e->getNewValue(), $e->getNote()],
			Server::get(EntitlementEventMapper::class)->findForEntitlement((int)$ent->getId()),
		);
	}

	public function testCarryOverUsedBeforeTheExpiryDateIsKept(): void {
		$this->entitlement(self::WITH_ROW, 2032, 28.0, 5.0);
		$this->leave(self::WITH_ROW, LeaveRequest::STATUS_APPROVED, '2032-02-09', 5.0);

		self::assertSame(0, $this->service('2032-04-10')->expireCarryOver(2032));

		$ent = $this->stored(self::WITH_ROW, 2032);
		self::assertSame(5.0, $ent->getCarryOverDays());
		self::assertSame([], $this->history($ent));
	}

	public function testOnlyTheUnusedCarryOverExpiresAndOnlyOnce(): void {
		$this->entitlement(self::WITH_ROW, 2032, 28.0, 5.0);
		$this->leave(self::WITH_ROW, LeaveRequest::STATUS_APPROVED, '2032-03-31', 2.0);
		// neither leave that is not approved yet nor leave after the date uses carry-over
		$this->leave(self::WITH_ROW, LeaveRequest::STATUS_PENDING, '2032-03-01', 1.0);
		$this->leave(self::WITH_ROW, LeaveRequest::STATUS_APPROVED, '2032-04-01', 3.0);

		self::assertSame(1, $this->service('2032-04-10')->expireCarryOver(2032));
		self::assertSame(0, $this->service('2032-04-11')->expireCarryOver(2032));

		$ent = $this->stored(self::WITH_ROW, 2032);
		self::assertSame(2.0, $ent->getCarryOverDays());
		self::assertSame(
			[[EntitlementEvent::ACTOR_SYSTEM, EntitlementEvent::FIELD_CARRY_OVER_DAYS, 5.0, 2.0, 'Carry-over expired']],
			$this->history($ent),
		);
	}

	public function testRolloverCoversEmployeesOnTheDefaultEntitlement(): void {
		// no row in 2031: 28 days by default, 25 taken
		$this->leave(self::ON_DEFAULT, LeaveRequest::STATUS_APPROVED, '2031-06-02', 25.0);
		// a row in 2031: 20 days, none taken, capped at 5
		$this->entitlement(self::WITH_ROW, 2031, 20.0);

		self::assertSame(['affected' => 2, 'skipped' => 0, 'failed' => 0], $this->service()->rollover(2031));

		$onDefault = $this->stored(self::ON_DEFAULT, 2032);
		self::assertSame([28.0, 3.0], [$onDefault->getBaseDays(), $onDefault->getCarryOverDays()]);
		self::assertSame(
			[[EntitlementEvent::ACTOR_SYSTEM, EntitlementEvent::FIELD_CARRY_OVER_DAYS, 0.0, 3.0, 'Year rollover']],
			$this->history($onDefault),
		);
		$withRow = $this->stored(self::WITH_ROW, 2032);
		self::assertSame([20.0, 5.0], [$withRow->getBaseDays(), $withRow->getCarryOverDays()]);
	}

	public function testRunningTheRolloverAgainRecomputesWhatItWrote(): void {
		$this->entitlement(self::WITH_ROW, 2031, 20.0);
		$this->service()->rollover(2031);
		// December leave approved after the first run
		$this->leave(self::WITH_ROW, LeaveRequest::STATUS_APPROVED, '2031-12-15', 18.0);

		$this->service()->rollover(2031);

		self::assertSame(2.0, $this->stored(self::WITH_ROW, 2032)->getCarryOverDays());
	}

	public function testRolloverLeavesACarryOverSetByHrAlone(): void {
		$this->entitlement(self::WITH_ROW, 2031, 20.0);
		$this->service()->setForEmployee(self::HR, self::WITH_ROW, 2032, $this->annual, ['carryOverDays' => 1.0]);

		$result = $this->service()->rollover(2031);

		self::assertSame(1, $result['skipped']);
		self::assertSame(1.0, $this->stored(self::WITH_ROW, 2032)->getCarryOverDays());
	}
}
