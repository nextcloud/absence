<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Who added and who last changed each public holiday (§7.2), and the history of
 * changes to the list, since everyone may be allowed to edit it.
 */
class Version1009Date20261022100000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		$table = $schema->getTable('absence_holidays');
		if (!$table->hasColumn('created_by')) {
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$changed = true;
		}
		if (!$schema->hasTable('absence_holiday_history')) {
			$history = $schema->createTable('absence_holiday_history');
			$history->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$history->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 16]);
			$history->addColumn('actor_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			// JSON: the holiday, before and after for a change, or the import count
			$history->addColumn('detail', Types::TEXT, ['notnull' => true]);
			$history->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$history->setPrimaryKey(['id']);
			$history->addIndex(['created_at'], 'absence_holhist_at_idx');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
