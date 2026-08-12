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
 * Adds `absence_entitlement_events`: the chronological record of who changed an
 * entitlement, which figure they changed, from what to what, and why (§6.1).
 *
 * Entitlement changes had nowhere to be recorded. Leave requests have their own
 * timeline in `absence_request_events`, but that table is keyed on `request_id`
 * and an entitlement belongs to no request — so an adjustment left only a line in
 * `nextcloud.log` and an activity entry reading "Leave balance of X was adjusted",
 * with neither the amount nor the reason. The note HR is *required* to write when
 * adjusting was stored on the entitlement row and displayed nowhere, and was
 * overwritten by the next adjustment.
 *
 * One row per changed figure rather than per save, so "+2 days for the wedding"
 * is a fact that can be read on its own instead of being diffed out of a blob.
 */
class Version1004Date20260812120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('absence_entitlement_events')) {
			return null;
		}

		$table = $schema->createTable('absence_entitlement_events');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('entitlement_id', Types::BIGINT, ['notnull' => true]);
		// Denormalised from the entitlement so the GDPR purge and any per-person
		// view can work without joining a row that is about to be deleted.
		$table->addColumn('employee_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('actor_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
		// 'base_days' | 'carry_over_days' | 'manual_adjustment'
		$table->addColumn('field', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('old_value', Types::FLOAT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('new_value', Types::FLOAT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('note', Types::TEXT, ['notnull' => false]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['entitlement_id'], 'absence_entev_ent');
		$table->addIndex(['employee_uid'], 'absence_entev_emp');

		return $schema;
	}
}
