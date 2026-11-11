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
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\ActivityPublisher;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\EntitlementService;
use OCA\Absence\Service\EntitlementValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
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
 * Entitlement edits on a real database (§6.1): what the history records for a
 * bulk change, and what happens when two writes meet.
 */
class EntitlementHistoryTest extends TestCase {
	private const GROUP = 'itest-enthist-team';
	private const ANA = 'itest-enthist-ana';
	private const BEN = 'itest-enthist-ben';
	private const HR = 'itest-enthist-hr';
	private const YEAR = 2033;

	private string $employeesGroup = '';
	private int $annual = 0;

	protected function setUp(): void {
		parent::setUp();
		$group = Server::get(IGroupManager::class)->createGroup(self::GROUP);
		foreach ([self::ANA, self::BEN] as $uid) {
			$group->addUser(Server::get(IUserManager::class)->createUser($uid, 'itest-' . bin2hex(random_bytes(8))));
		}
		$this->employeesGroup = Server::get(ConfigService::class)->getEmployeesGroup();
		Server::get(IAppConfig::class)->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_EMPLOYEES_GROUP, '');
		foreach (Server::get(LeaveTypeMapper::class)->findAll() as $type) {
			if ($type->getKey() === 'annual') {
				$this->annual = (int)$type->getId();
			}
		}
	}

	protected function tearDown(): void {
		Server::get(IAppConfig::class)->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_EMPLOYEES_GROUP, $this->employeesGroup);
		$db = Server::get(IDBConnection::class);
		foreach (['absence_entitlements', 'absence_ent_events'] as $table) {
			foreach ([self::ANA, self::BEN] as $uid) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($uid)))->executeStatement();
			}
		}
		foreach ([self::ANA, self::BEN] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		Server::get(IGroupManager::class)->get(self::GROUP)?->delete();
		parent::tearDown();
	}

	private function balances(EntitlementMapper $mapper): BalanceService {
		return new BalanceService(
			Server::get(LeaveRequestMapper::class),
			$mapper,
			Server::get(LeaveTypeMapper::class),
			Server::get(ConfigService::class),
			Server::get(ClockService::class),
			Server::get(IFactory::class)->get(ConfigService::APP_ID),
		);
	}

	/** The real service, on the given mapper when a test stands in for a second writer. */
	private function service(?EntitlementMapper $mapper = null): EntitlementService {
		$mapper ??= Server::get(EntitlementMapper::class);
		$config = Server::get(ConfigService::class);
		$l = Server::get(IFactory::class)->get(ConfigService::APP_ID);
		return new EntitlementService(
			$mapper,
			Server::get(EntitlementEventMapper::class),
			Server::get(LeaveRequestMapper::class),
			Server::get(LeaveTypeMapper::class),
			$this->balances($mapper),
			$config,
			Server::get(ClockService::class),
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

	private function entitlement(string $uid, float $base): Entitlement {
		$ent = new Entitlement();
		$ent->setEmployeeUid($uid);
		$ent->setYear(self::YEAR);
		$ent->setTypeId($this->annual);
		$ent->setBaseDays($base);
		$ent->setCarryOverDays(0.0);
		$ent->setManualAdjustment(0.0);
		$ent->setCreatedAt(new \DateTime('2033-01-01 09:00:00'));
		$ent->setUpdatedAt(new \DateTime('2033-01-01 09:00:00'));
		return Server::get(EntitlementMapper::class)->insert($ent);
	}

	private function stored(string $uid): Entitlement {
		return Server::get(EntitlementMapper::class)->findFor($uid, self::YEAR, $this->annual);
	}

	/**
	 * @return list<array{string,string,float,float}> actor, field, old, new
	 */
	private function history(string $uid): array {
		return array_map(
			static fn (EntitlementEvent $e): array => [$e->getActorUid(), $e->getField(), $e->getOldValue(), $e->getNewValue()],
			Server::get(EntitlementEventMapper::class)->findForEmployee($uid),
		);
	}

	public function testABulkChangeIsRecordedInEachHistoryWithItsActor(): void {
		$this->entitlement(self::ANA, 20.0);
		// Ben has no row yet and lives on the default
		$default = Server::get(ConfigService::class)->getDefaultEntitlement();

		self::assertSame(2, $this->service()->bulkSet(self::HR, self::YEAR, $this->annual, $default + 2.0, self::GROUP));

		self::assertSame($default + 2.0, $this->stored(self::BEN)->getBaseDays());
		self::assertSame([[self::HR, EntitlementEvent::FIELD_BASE_DAYS, 20.0, $default + 2.0]], $this->history(self::ANA));
		self::assertSame([[self::HR, EntitlementEvent::FIELD_BASE_DAYS, $default, $default + 2.0]], $this->history(self::BEN));
	}

	public function testABulkChangeThatFailsPartWayChangesNobody(): void {
		$this->entitlement(self::ANA, 20.0);
		$this->entitlement(self::BEN, 20.0);
		$failsOnTheSecondRow = new class(Server::get(IDBConnection::class)) extends EntitlementMapper {
			private int $updates = 0;

			#[\Override]
			public function update(Entity $entity): Entity {
				if (++$this->updates === 2) {
					throw new \RuntimeException('database is away');
				}
				return parent::update($entity);
			}
		};

		try {
			$this->service($failsOnTheSecondRow)->bulkSet(self::HR, self::YEAR, $this->annual, 30.0, self::GROUP);
			self::fail('expected the failure to surface');
		} catch (\RuntimeException) {
		}

		self::assertSame([20.0, 20.0], [$this->stored(self::ANA)->getBaseDays(), $this->stored(self::BEN)->getBaseDays()]);
		self::assertSame([], [...$this->history(self::ANA), ...$this->history(self::BEN)]);
	}

	public function testTwoCorrectionsAtTheSameMomentBothCount(): void {
		$id = (int)$this->entitlement(self::ANA, 20.0)->getId();
		// A second correction is saved right after this one read the row.
		$other = fn () => $this->service()->update(self::HR, $id, ['adjustmentDelta' => 3.0, 'adjustmentNote' => 'itest overtime']);
		$interleaved = new class(Server::get(IDBConnection::class), $other) extends EntitlementMapper {
			public function __construct(
				IDBConnection $db,
				private ?\Closure $afterRead,
			) {
				parent::__construct($db);
			}

			#[\Override]
			public function find(int $id): Entitlement {
				$ent = parent::find($id);
				$afterRead = $this->afterRead;
				$this->afterRead = null;
				if ($afterRead !== null) {
					$afterRead();
				}
				return $ent;
			}
		};

		$this->service($interleaved)->update(self::HR, $id, ['adjustmentDelta' => 2.0, 'adjustmentNote' => 'itest wedding']);

		self::assertSame(5.0, $this->stored(self::ANA)->getManualAdjustment());
	}

	public function testARowCreatedByAnotherRequestInTheMeantimeIsUsed(): void {
		$existing = $this->entitlement(self::ANA, 20.0);
		// The lookup ran just before the other request inserted the row.
		$missesOnce = new class(Server::get(IDBConnection::class)) extends EntitlementMapper {
			private bool $missed = false;

			#[\Override]
			public function findFor(string $employeeUid, int $year, int $typeId): Entitlement {
				if (!$this->missed) {
					$this->missed = true;
					throw new DoesNotExistException('not there yet');
				}
				return parent::findFor($employeeUid, $year, $typeId);
			}
		};

		$ent = $this->balances($missesOnce)->ensureEntitlement(self::ANA, self::YEAR, $this->annual);

		self::assertSame([$existing->getId(), 20.0], [$ent->getId(), $ent->getBaseDays()]);
	}
}
