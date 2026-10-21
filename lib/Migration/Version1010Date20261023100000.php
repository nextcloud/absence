<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use Closure;
use OCA\Absence\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Contract data HR keeps per employee (§6.4): start and end date, the working
 * weekdays and contract percentage from a date on, and the history of changes.
 * The weekdays people set for themselves for the prefill are dropped: HR owns
 * them now.
 */
class Version1010Date20261023100000 extends SimpleMigrationStep {
	public function __construct(
		private IUserConfig $userConfig,
	) {
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		if (!$schema->hasTable('absence_employments')) {
			$table = $schema->createTable('absence_employments');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('employee_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('start_date', Types::DATE, ['notnull' => false]);
			$table->addColumn('end_date', Types::DATE, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['employee_uid'], 'absence_employ_uid_uniq');
			$changed = true;
		}
		if (!$schema->hasTable('absence_contract_periods')) {
			$table = $schema->createTable('absence_contract_periods');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('employee_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('valid_from', Types::DATE, ['notnull' => true]);
			// ISO weekdays, comma separated (Mon=1)
			$table->addColumn('weekdays', Types::STRING, ['notnull' => true, 'length' => 20]);
			$table->addColumn('percent', Types::SMALLINT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['employee_uid', 'valid_from'], 'absence_period_uid_from_uniq');
			$changed = true;
		}
		if (!$schema->hasTable('absence_employee_history')) {
			$table = $schema->createTable('absence_employee_history');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('employee_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('actor_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('field', Types::STRING, ['notnull' => true, 'length' => 32]);
			// JSON: before and after
			$table->addColumn('detail', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['employee_uid', 'created_at'], 'absence_emphist_uid_idx');
			$changed = true;
		}
		return $changed ? $schema : null;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->userConfig->deleteKey(Application::APP_ID, 'work_weekdays');
	}
}
