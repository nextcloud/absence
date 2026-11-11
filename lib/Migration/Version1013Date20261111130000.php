<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Three tables get a shorter name. Oracle keeps identifiers to 30 characters,
 * and the sequence behind an id is named after its table: with the prefix and
 * `_seq`, a table name over 23 characters gets a sequence the server cannot
 * find again, so nothing could be inserted there.
 *
 * The rows are copied here, and the next step drops the old tables.
 */
class Version1013Date20261111130000 extends SimpleMigrationStep {
	/** Old name, new name and the columns to copy (every one but the id) */
	public const RENAMED = [
		['absence_contract_periods', 'absence_periods', ['employee_uid', 'valid_from', 'weekdays', 'percent']],
		['absence_employee_history', 'absence_emp_history', ['employee_uid', 'actor_uid', 'field', 'detail', 'created_at']],
		['absence_entitlement_events', 'absence_ent_events', ['entitlement_id', 'employee_uid', 'actor_uid', 'field', 'old_value', 'new_value', 'note', 'created_at']],
	];

	private const CHUNK = 500;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;
		if (!$schema->hasTable('absence_periods')) {
			$table = $schema->createTable('absence_periods');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('employee_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			// YYYY-MM-DD
			$table->addColumn('valid_from', Types::STRING, ['notnull' => true, 'length' => 10]);
			// ISO weekdays, comma separated (Mon=1)
			$table->addColumn('weekdays', Types::STRING, ['notnull' => true, 'length' => 20]);
			$table->addColumn('percent', Types::SMALLINT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['employee_uid', 'valid_from'], 'absence_per_uid_from');
			$changed = true;
		}
		if (!$schema->hasTable('absence_emp_history')) {
			$table = $schema->createTable('absence_emp_history');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('employee_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('actor_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('field', Types::STRING, ['notnull' => true, 'length' => 32]);
			// JSON: before and after
			$table->addColumn('detail', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['employee_uid', 'created_at'], 'absence_emph_uid');
			$changed = true;
		}
		if (!$schema->hasTable('absence_ent_events')) {
			$table = $schema->createTable('absence_ent_events');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('entitlement_id', Types::BIGINT, ['notnull' => true]);
			// Denormalised from the entitlement so the GDPR purge and any per-person
			// view can work without joining a row that is about to be deleted.
			$table->addColumn('employee_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			// Null for a change made from the command line
			$table->addColumn('actor_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			// 'base_days' | 'carry_over_days' | 'manual_adjustment'
			$table->addColumn('field', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('old_value', Types::FLOAT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('new_value', Types::FLOAT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('note', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['entitlement_id'], 'absence_entv_ent');
			$table->addIndex(['employee_uid'], 'absence_entv_emp');
			$changed = true;
		}
		return $changed ? $schema : null;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		foreach (self::RENAMED as [$old, $new, $columns]) {
			if ($schema->hasTable($old) && $this->isEmpty($new)) {
				$output->info(sprintf('Copied %d rows from %s to %s', $this->copy($old, $new, $columns), $old, $new));
			}
		}
	}

	private function isEmpty(string $table): bool {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('id')->from($table)->setMaxResults(1)->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false;
	}

	/**
	 * Copies in the order of the old ids. The ids themselves are not kept: a
	 * sequence does not follow ids written by hand, and nothing refers to them.
	 *
	 * @param list<string> $columns
	 */
	private function copy(string $old, string $new, array $columns): int {
		$copied = 0;
		$lastId = 0;
		do {
			$select = $this->db->getQueryBuilder();
			$select->select('id', ...$columns)
				->from($old)
				->where($select->expr()->gt('id', $select->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
				->orderBy('id')
				->setMaxResults(self::CHUNK);
			$result = $select->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();

			foreach ($rows as $row) {
				$insert = $this->db->getQueryBuilder();
				$insert->insert($new);
				foreach ($columns as $column) {
					/** @var scalar|null $value */
					$value = $row[$column];
					$insert->setValue($column, $value === null
						? $insert->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
						: $insert->createNamedParameter((string)$value));
				}
				$insert->executeStatement();
				$lastId = (int)$row['id'];
				$copied++;
			}
		} while (count($rows) === self::CHUNK);
		return $copied;
	}
}
