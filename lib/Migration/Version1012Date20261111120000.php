<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use Closure;
use Doctrine\DBAL\Types\Type;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Days are kept as `YYYY-MM-DD` text, like the out-of-office days of the server
 * (`dav_absence`). A DATE column comes back with a time part on Oracle, and the
 * app reads, compares and sends these values as text everywhere.
 *
 * Other databases convert the values with the column. Oracle only changes the
 * type of a column without values, so there the days are first written as text
 * to a new column that then takes the place of the old one.
 */
class Version1012Date20261111120000 extends SimpleMigrationStep {
	private const DAYS = [
		'absence_requests' => ['start_date', 'end_date'],
		'absence_holidays' => ['date'],
		'absence_employments' => ['start_date', 'end_date'],
		'absence_contract_periods' => ['valid_from'],
	];

	/** The indexes over a day column: Oracle drops them with the column it replaces */
	private const INDEXES = [
		'absence_requests' => ['absence_req_range_idx' => [['start_date', 'end_date'], false]],
		'absence_holidays' => ['absence_hol_date_idx' => [['date'], false]],
		'absence_contract_periods' => ['absence_period_uid_from_uniq' => [['employee_uid', 'valid_from'], true]],
	];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	#[\Override]
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_ORACLE) {
			return;
		}
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$qb = $this->db->getQueryBuilder();
		foreach (self::DAYS as $name => $columns) {
			if (!$schema->hasTable($name)) {
				continue;
			}
			$table = $schema->getTable($name);
			$quotedTable = $qb->getTableName($name);
			foreach ($columns as $columnName) {
				if (!$table->hasColumn($columnName) || self::isText($table->getColumn($columnName))) {
					continue;
				}
				$column = $qb->getColumnName($columnName);
				$text = $qb->getColumnName($columnName . '_txt');
				$this->db->executeStatement("ALTER TABLE $quotedTable ADD ($text VARCHAR2(10))");
				$this->db->executeStatement("UPDATE $quotedTable SET $text = TO_CHAR($column, 'YYYY-MM-DD')");
				$this->db->executeStatement("ALTER TABLE $quotedTable DROP COLUMN $column");
				$this->db->executeStatement("ALTER TABLE $quotedTable RENAME COLUMN $text TO $column");
				if ($table->getColumn($columnName)->getNotnull()) {
					$this->db->executeStatement("ALTER TABLE $quotedTable MODIFY ($column NOT NULL)");
				}
			}
		}
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;
		foreach (self::DAYS as $name => $columns) {
			if (!$schema->hasTable($name)) {
				continue;
			}
			$table = $schema->getTable($name);
			foreach ($columns as $columnName) {
				if (!$table->hasColumn($columnName)) {
					continue;
				}
				$column = $table->getColumn($columnName);
				if (!self::isText($column)) {
					/** @psalm-suppress UndefinedClass Doctrine is not part of the OCP stubs */
					$column->setType($column->getType() instanceof \BackedEnum ? Types::STRING : Type::getType(Types::STRING));
					$column->setLength(10);
					$changed = true;
				}
			}
			foreach (self::INDEXES[$name] ?? [] as $index => [$indexed, $unique]) {
				if (!$table->hasIndex($index)) {
					$unique ? $table->addUniqueIndex($indexed, $index) : $table->addIndex($indexed, $index);
					$changed = true;
				}
			}
		}
		return $changed ? $schema : null;
	}

	/**
	 * Nextcloud 35 and later hand out their own column, with an enum as type;
	 * Nextcloud 34 hands out the Doctrine one.
	 */
	private static function isText(object $column): bool {
		/** @psalm-suppress MixedMethodCall the column class depends on the server version */
		$type = $column->getType();
		/** @psalm-suppress UndefinedClass Doctrine is not part of the OCP stubs */
		return ($type instanceof \BackedEnum ? $type->value : Type::lookupName($type)) === Types::STRING;
	}
}
