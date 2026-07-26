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
 * Brings instances that installed an earlier build up to the current schema:
 * the per-request history table (§3.7) and the `icon` / `calendar_event_uri`
 * columns. All changes are guarded, so this is a no-op on fresh installs where
 * {@see Version1000Date20260710000000} already created them.
 */
class Version1001Date20260711000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		// History timeline table.
		if (!$schema->hasTable('absence_request_events')) {
			$table = $schema->createTable('absence_request_events');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('request_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('actor_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('event_type', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('detail', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['request_id'], 'absence_evt_req_idx');
			$changed = true;
		}

		// calendar_event_uri on requests (§10).
		if ($schema->hasTable('absence_requests')) {
			$table = $schema->getTable('absence_requests');
			if (!$table->hasColumn('calendar_event_uri')) {
				$table->addColumn('calendar_event_uri', Types::STRING, ['notnull' => false, 'length' => 255]);
				$changed = true;
			}
		}

		// icon on leave types (§3.2).
		if ($schema->hasTable('absence_leave_types')) {
			$table = $schema->getTable('absence_leave_types');
			if (!$table->hasColumn('icon')) {
				$table->addColumn('icon', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => '🌴']);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
