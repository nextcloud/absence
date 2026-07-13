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
use OCP\Migration\IMigrationStep;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema for the Absence app (spec §3).
 */
class Version1000Date20260710000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// --- absence_leave_types -------------------------------------------------
		if (!$schema->hasTable('absence_leave_types')) {
			$table = $schema->createTable('absence_leave_types');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('key', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('color', Types::STRING, ['notnull' => true, 'length' => 7, 'default' => '#0082c9']);
			$table->addColumn('icon', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => '🌴']);
			$table->addColumn('counts_against_balance', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$table->addColumn('requires_approval', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$table->addColumn('requires_note', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('requires_replacement', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('employee_requestable', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$table->addColumn('enabled', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$table->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['key'], 'absence_lt_key_idx');
		}

		// --- absence_requests ----------------------------------------------------
		if (!$schema->hasTable('absence_requests')) {
			$table = $schema->createTable('absence_requests');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('employee_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('manager_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('type_id', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('start_date', Types::DATE, ['notnull' => true]);
			$table->addColumn('end_date', Types::DATE, ['notnull' => true]);
			$table->addColumn('working_days', Types::DECIMAL, ['notnull' => true, 'precision' => 5, 'scale' => 1, 'default' => 0]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'PENDING']);
			$table->addColumn('reason', Types::TEXT, ['notnull' => false]);
			$table->addColumn('replacement_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('attachment_note', Types::TEXT, ['notnull' => false]);
			$table->addColumn('decided_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('decided_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('decision_comment', Types::TEXT, ['notnull' => false]);
			$table->addColumn('escalated', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('supersedes_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('calendar_event_uri', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['employee_uid', 'status'], 'absence_req_emp_idx');
			$table->addIndex(['manager_uid', 'status'], 'absence_req_mgr_idx');
			$table->addIndex(['start_date', 'end_date'], 'absence_req_range_idx');
			$table->addIndex(['type_id'], 'absence_req_type_idx');
		}

		// --- absence_entitlements ------------------------------------------------
		if (!$schema->hasTable('absence_entitlements')) {
			$table = $schema->createTable('absence_entitlements');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('employee_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('year', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('type_id', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('base_days', Types::DECIMAL, ['notnull' => true, 'precision' => 5, 'scale' => 1, 'default' => 0]);
			$table->addColumn('carry_over_days', Types::DECIMAL, ['notnull' => true, 'precision' => 5, 'scale' => 1, 'default' => 0]);
			$table->addColumn('manual_adjustment', Types::DECIMAL, ['notnull' => true, 'precision' => 5, 'scale' => 1, 'default' => 0]);
			$table->addColumn('adjustment_note', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['employee_uid', 'year', 'type_id'], 'absence_ent_uniq_idx');
		}

		// --- absence_holidays ----------------------------------------------------
		if (!$schema->hasTable('absence_holidays')) {
			$table = $schema->createTable('absence_holidays');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('region', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => 'default']);
			$table->addColumn('date', Types::DATE, ['notnull' => true]);
			$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('recurring', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('year', Types::INTEGER, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['region'], 'absence_hol_region_idx');
			$table->addIndex(['date'], 'absence_hol_date_idx');
		}

		// --- absence_comments ----------------------------------------------------
		if (!$schema->hasTable('absence_comments')) {
			$table = $schema->createTable('absence_comments');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('request_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('author_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('body', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['request_id'], 'absence_com_req_idx');
		}

		// --- absence_request_events (history timeline) ---------------------------
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
		}

		return $schema;
	}
}
