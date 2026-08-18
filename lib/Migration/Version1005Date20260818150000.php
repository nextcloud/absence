<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Two indexes the hot paths were missing:
 *
 *  - `(status, created_at)` — the escalation job, the reminder job and the HR
 *    escalated queue all scan `WHERE status = … AND created_at < …`; without a
 *    matching index each of those was a full-table walk, hourly in the jobs'
 *    case and on every HR session load for the badge count.
 *  - `supersedes_id` — the supersedes chain is read inside every edit, every
 *    approval and every overlap check ({@see \OCA\Absence\Db\LeaveRequestMapper::findBySupersedesId()}),
 *    and it was an unindexed column scan.
 */
class Version1005Date20260818150000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('absence_requests');
		$changed = false;
		if (!$table->hasIndex('absence_req_status_idx')) {
			$table->addIndex(['status', 'created_at'], 'absence_req_status_idx');
			$changed = true;
		}
		if (!$table->hasIndex('absence_req_super_idx')) {
			$table->addIndex(['supersedes_id'], 'absence_req_super_idx');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
