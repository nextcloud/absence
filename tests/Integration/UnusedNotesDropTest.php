<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\Absence\Migration\Version1023Date20261118140000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The upgrade drops the note of a request and the note on an entitlement
 * (§3.1, §3.4) and keeps the rows, against a real database.
 */
class UnusedNotesDropTest extends TestCase {
	private const UID = 'itest-notes-drop';
	private const COLUMNS = ['absence_requests' => 'attachment_note', 'absence_entitlements' => 'adjustment_note'];

	private Connection $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(Connection::class);
		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
		parent::tearDown();
	}

	public function testTheNotesGoAndTheRowsStay(): void {
		$this->addOldColumns();
		$this->insert('absence_requests', [
			'employee_uid' => self::UID, 'type_id' => [1, IQueryBuilder::PARAM_INT], 'start_date' => '2026-03-02', 'end_date' => '2026-03-03',
			'working_days' => '2', 'status' => 'APPROVED', 'reason' => 'itest reason', 'attachment_note' => 'itest note',
			'created_at' => [new \DateTime('2026-03-01 09:00:00'), IQueryBuilder::PARAM_DATETIME_MUTABLE],
			'updated_at' => [new \DateTime('2026-03-01 09:00:00'), IQueryBuilder::PARAM_DATETIME_MUTABLE],
		]);
		$this->insert('absence_entitlements', [
			'employee_uid' => self::UID, 'year' => [2026, IQueryBuilder::PARAM_INT], 'type_id' => [1, IQueryBuilder::PARAM_INT],
			'base_days' => '28', 'carry_over_days' => '0', 'manual_adjustment' => '2', 'adjustment_note' => 'itest wedding',
			'created_at' => [new \DateTime('2026-01-02 08:00:00'), IQueryBuilder::PARAM_DATETIME_MUTABLE],
			'updated_at' => [new \DateTime('2026-01-02 08:00:00'), IQueryBuilder::PARAM_DATETIME_MUTABLE],
		]);

		$this->migrate();

		$schema = new SchemaWrapper($this->db);
		foreach (self::COLUMNS as $table => $column) {
			self::assertFalse($schema->getTable($table)->hasColumn($column), $table);
		}
		self::assertSame([['reason' => 'itest reason']], $this->rows('absence_requests', ['reason']));
		self::assertSame(['2'], array_map(static fn (array $r): string => rtrim(rtrim((string)$r['manual_adjustment'], '0'), '.'), $this->rows('absence_entitlements', ['manual_adjustment'])));
		self::assertNull(Server::get(Version1023Date20261118140000::class)->changeSchema(
			$this->createMock(IOutput::class),
			fn (): ISchemaWrapper => new SchemaWrapper($this->db),
			[],
		));
	}

	/** The columns as every version before the drop created them. */
	private function addOldColumns(): void {
		$schema = new SchemaWrapper($this->db);
		foreach (self::COLUMNS as $table => $column) {
			if (!$schema->getTable($table)->hasColumn($column)) {
				$schema->getTable($table)->addColumn($column, Types::TEXT, ['notnull' => false]);
			}
		}
		$this->db->migrateToSchema($schema->getWrappedSchema());
	}

	private function migrate(): void {
		$schema = Server::get(Version1023Date20261118140000::class)->changeSchema(
			$this->createMock(IOutput::class),
			fn (): ISchemaWrapper => new SchemaWrapper($this->db),
			[],
		);
		self::assertInstanceOf(SchemaWrapper::class, $schema);
		$this->db->migrateToSchema($schema->getWrappedSchema());
	}

	/**
	 * @param array<string, string|array{0: mixed, 1: int}> $values
	 */
	private function insert(string $table, array $values): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table)->values(array_map(
			static fn (string|array $value) => is_array($value) ? $qb->createNamedParameter($value[0], $value[1]) : $qb->createNamedParameter($value),
			$values,
		))->executeStatement();
	}

	/**
	 * @param list<string> $columns
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $table, array $columns): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select(...$columns)
			->from($table)
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter(self::UID)))
			->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	private function cleanUp(): void {
		foreach (array_keys(self::COLUMNS) as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter(self::UID)))
				->executeStatement();
		}
	}
}
