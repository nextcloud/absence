<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\Absence\Db\ContractPeriodMapper;
use OCA\Absence\Migration\Version1010Date20261023100000;
use OCA\Absence\Migration\Version1013Date20261111130000;
use OCA\Absence\Migration\Version1014Date20261111140000;
use OCA\Absence\Migration\Version1018Date20261117180000;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeService;
use OCP\Config\IUserConfig;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * An upgrade from 1.0.12 keeps what people entered there: the weekdays they
 * set for themselves become their contract, and the renamed tables are only
 * dropped once every row is in the new one.
 */
class UpgradeKeepsDataTest extends TestCase {
	private const PREFIX = 'itest-upgrade-';
	private const KEY = 'work_weekdays';
	private const OLD_EVENTS = 'absence_entitlement_events';
	private const NEW_EVENTS = 'absence_ent_events';

	private Connection $db;
	private IUserConfig $userConfig;

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(Connection::class);
		$this->userConfig = Server::get(IUserConfig::class);
		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
		parent::tearDown();
	}

	public function testOwnWeekdaysBecomeAContract(): void {
		$alice = self::PREFIX . 'alice';
		$bob = self::PREFIX . 'bob';
		$numeric = '90817';
		$grace = self::PREFIX . 'grace';
		$invalid = [
			self::PREFIX . 'charlie' => '1,8',
			self::PREFIX . 'dave' => '2,2,3',
			self::PREFIX . 'erin' => '',
			self::PREFIX . 'frank' => 'mon,tue',
		];
		$prefs = [$alice => '1,2,3,4,5', $bob => '5,1,3', $numeric => '2,4', $grace => '3,4'] + $invalid;
		foreach ($prefs as $uid => $value) {
			$this->userConfig->setValueString((string)$uid, ConfigService::APP_ID, self::KEY, $value);
		}
		$this->insert('absence_periods', ['employee_uid' => $grace, 'valid_from' => '2020-03-01', 'weekdays' => '1,2', 'percent' => null]);

		$this->runPostSchemaChange(Server::get(Version1018Date20261117180000::class));

		$from = Server::get(ClockService::class)->serverYear() . '-01-01';
		$periods = Server::get(ContractPeriodMapper::class);
		$describe = static fn (string $uid): array => array_map(
			static fn ($period): array => [$period->getValidFrom(), $period->getWeekdays(), $period->getPercent()],
			$periods->findForEmployee($uid),
		);
		self::assertSame([[$from, '1,2,3,4,5', null]], $describe($alice));
		self::assertSame([[$from, '1,3,5', null]], $describe($bob));
		self::assertSame([[$from, '2,4', null]], $describe($numeric));
		self::assertSame([['2020-03-01', '1,2', null]], $describe($grace));
		foreach (array_keys($invalid) as $uid) {
			self::assertSame([], $describe($uid), $uid);
		}

		$history = Server::get(EmployeeService::class)->history($bob);
		self::assertCount(1, $history);
		self::assertSame(['contract', null], [$history[0]['field'], $history[0]['actor']]);
		self::assertSame(['start' => null, 'end' => null, 'periods' => []], $history[0]['before']);
		self::assertSame(['start' => null, 'end' => null, 'periods' => [['from' => $from, 'weekdays' => [1, 3, 5], 'percent' => null]]], $history[0]['after']);
		self::assertSame('own_setting', json_decode($this->historyDetails($bob)[0], true)['source']);
		self::assertCount(1, $this->historyDetails($alice));
		self::assertCount(1, $this->historyDetails($numeric));
		self::assertSame([], $this->historyDetails($grace));
		foreach (array_keys($invalid) as $uid) {
			self::assertSame([], $this->historyDetails($uid), $uid);
		}
		self::assertSame([], $this->prefs());

		$before = [$this->rows('absence_periods'), $this->rows('absence_emp_history')];
		$this->runPostSchemaChange(Server::get(Version1018Date20261117180000::class));
		self::assertSame($before, [$this->rows('absence_periods'), $this->rows('absence_emp_history')]);
	}

	public function testTheContractStepKeepsOwnWeekdays(): void {
		$this->userConfig->setValueString(self::PREFIX . 'alice', ConfigService::APP_ID, self::KEY, '1,2,3');

		$this->runPostSchemaChange(Server::get(Version1010Date20261023100000::class));

		self::assertSame([self::PREFIX . 'alice' => '1,2,3'], $this->prefs());
	}

	public function testACopyThatFailsLeavesTheNewTableEmpty(): void {
		self::assertSame(0, $this->rows(self::NEW_EVENTS), 'The test needs an empty ' . self::NEW_EVENTS);
		// `field` may be null here, so the second row cannot go into the new table
		$this->createOldEvents(fieldNullable: true);
		$this->insertOldEvents(['base_days', null, 'manual_adjustment']);

		$migration = Server::get(Version1013Date20261111130000::class);
		$error = null;
		try {
			$this->runPostSchemaChange($migration);
		} catch (\Throwable $e) {
			$error = $e;
		}
		self::assertNotNull($error, 'The copy did not fail');
		self::assertSame(0, $this->rows(self::NEW_EVENTS));

		$qb = $this->db->getQueryBuilder();
		$qb->update(self::OLD_EVENTS)
			->set('field', $qb->createNamedParameter('carry_over_days'))
			->where($qb->expr()->isNull('field'))
			->executeStatement();
		$this->runPostSchemaChange($migration);
		self::assertSame(3, $this->rows(self::NEW_EVENTS));
	}

	public function testOldTableStaysWhenTheNewOneHasFewerRows(): void {
		self::assertSame(0, $this->rows(self::NEW_EVENTS), 'The test needs an empty ' . self::NEW_EVENTS);
		$this->createOldEvents();
		$this->insertOldEvents(['base_days', 'carry_over_days', 'manual_adjustment']);
		$this->insertNewEvent();

		$error = null;
		try {
			$this->runChangeSchema(Server::get(Version1014Date20261111140000::class));
		} catch (\RuntimeException $e) {
			$error = $e;
		}
		self::assertNotNull($error, 'The old table was dropped');
		self::assertMatchesRegularExpression('/absence_entitlement_events\\D+3\\D+absence_ent_events\\D+1\\D/', $error->getMessage());
		self::assertTrue($this->db->tableExists(self::OLD_EVENTS));
		self::assertSame(3, $this->rows(self::OLD_EVENTS));
	}

	public function testOldTableIsDroppedOnceEveryRowIsCopied(): void {
		self::assertSame(0, $this->rows(self::NEW_EVENTS), 'The test needs an empty ' . self::NEW_EVENTS);
		$this->createOldEvents();
		$this->insertOldEvents(['base_days']);
		$this->insertNewEvent();

		$this->runChangeSchema(Server::get(Version1014Date20261111140000::class));

		self::assertFalse($this->db->tableExists(self::OLD_EVENTS));
	}

	public function testAFreshInstallDropsTheOldTablesWithoutCounting(): void {
		// A new install reads every step's schema change before writing any table
		$schema = new SchemaWrapper($this->db);
		$schema->createTable(self::OLD_EVENTS)->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);

		$result = Server::get(Version1014Date20261111140000::class)
			->changeSchema($this->createMock(IOutput::class), static fn (): ISchemaWrapper => $schema, []);

		self::assertFalse($result?->hasTable(self::OLD_EVENTS));
	}

	private function runPostSchemaChange(\OCP\Migration\IMigrationStep $migration): void {
		$migration->postSchemaChange($this->createMock(IOutput::class), fn (): ISchemaWrapper => new SchemaWrapper($this->db), []);
	}

	private function runChangeSchema(\OCP\Migration\IMigrationStep $migration): void {
		$schema = $migration->changeSchema($this->createMock(IOutput::class), fn (): ISchemaWrapper => new SchemaWrapper($this->db), []);
		if ($schema instanceof SchemaWrapper) {
			$this->db->migrateToSchema($schema->getWrappedSchema());
			$schema->performDropTableCalls();
		}
	}

	/**
	 * The entitlement history table as 1.0.12 created it.
	 */
	private function createOldEvents(bool $fieldNullable = false): void {
		$schema = new SchemaWrapper($this->db);
		$table = $schema->createTable(self::OLD_EVENTS);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('entitlement_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('employee_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('actor_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('field', Types::STRING, ['notnull' => !$fieldNullable, 'length' => 32]);
		$table->addColumn('old_value', Types::FLOAT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('new_value', Types::FLOAT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('note', Types::TEXT, ['notnull' => false]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['entitlement_id'], 'absence_entev_ent');
		$table->addIndex(['employee_uid'], 'absence_entev_emp');
		$this->db->migrateToSchema($schema->getWrappedSchema());
	}

	/**
	 * @param list<?string> $fields
	 */
	private function insertOldEvents(array $fields): void {
		foreach ($fields as $i => $field) {
			$this->insert(self::OLD_EVENTS, [
				'entitlement_id' => 990001,
				'employee_uid' => self::PREFIX . 'alice',
				'actor_uid' => self::PREFIX . 'hr',
				'field' => $field,
				'old_value' => (string)$i,
				'new_value' => (string)($i + 1),
				'note' => null,
				'created_at' => '2026-08-20 10:00:00',
			]);
		}
	}

	private function insertNewEvent(): void {
		$this->insert(self::NEW_EVENTS, [
			'entitlement_id' => 990001,
			'employee_uid' => self::PREFIX . 'alice',
			'actor_uid' => self::PREFIX . 'hr',
			'field' => 'base_days',
			'old_value' => '0',
			'new_value' => '1',
			'note' => null,
			'created_at' => '2026-08-20 10:00:00',
		]);
	}

	/**
	 * @param array<string, scalar|null> $values
	 */
	private function insert(string $table, array $values): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table);
		foreach ($values as $column => $value) {
			$qb->setValue($column, $value === null
				? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
				: $qb->createNamedParameter((string)$value));
		}
		$qb->executeStatement();
	}

	private function rows(string $table): int {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($qb->func()->count('*'))->from($table)->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * @return list<string>
	 */
	private function historyDetails(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('detail')
			->from('absence_emp_history')
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($uid)))
			->executeQuery();
		$details = $result->fetchFirstColumn();
		$result->closeCursor();
		return $details;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function prefs(): array {
		// From the table: the server's typed read fails on Oracle, where the
		// empty value of one of these people is stored as NULL.
		$qb = $this->db->getQueryBuilder();
		$qb->select('userid', 'configvalue')
			->from('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(ConfigService::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(self::KEY)));
		$result = $qb->executeQuery();
		$prefs = [];
		while ($row = $result->fetch()) {
			$prefs[(string)$row['userid']] = (string)($row['configvalue'] ?? '');
		}
		$result->closeCursor();
		return $prefs;
	}

	private function cleanUp(): void {
		$this->userConfig->deleteKey(ConfigService::APP_ID, self::KEY);
		foreach (['absence_periods', 'absence_emp_history', self::NEW_EVENTS] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->orX(
					$qb->expr()->like('employee_uid', $qb->createNamedParameter(self::PREFIX . '%')),
					$qb->expr()->eq('employee_uid', $qb->createNamedParameter('90817')),
				))
				->executeStatement();
		}
		if ($this->db->tableExists(self::OLD_EVENTS)) {
			$this->db->dropTable(self::OLD_EVENTS);
		}
	}
}
