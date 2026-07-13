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
 * Adds the mandatory-replacement feature (§5.1): a `requires_replacement` flag on
 * leave types (seeded true for annual/unpaid/special) and a `replacement_uid` column
 * on requests. Guarded, so a no-op on fresh installs.
 */
class Version1003Date20260711140000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('absence_leave_types')) {
			$table = $schema->getTable('absence_leave_types');
			if (!$table->hasColumn('requires_replacement')) {
				$table->addColumn('requires_replacement', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
				$changed = true;
			}
		}
		if ($schema->hasTable('absence_requests')) {
			$table = $schema->getTable('absence_requests');
			if (!$table->hasColumn('replacement_uid')) {
				$table->addColumn('replacement_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Self-requestable, approval-based types require a replacement by default.
		$qb = $this->db->getQueryBuilder();
		$qb->update('absence_leave_types')
			->set('requires_replacement', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->in('key', $qb->createNamedParameter(['annual', 'unpaid', 'special'], IQueryBuilder::PARAM_STR_ARRAY)));
		$affected = $qb->executeStatement();
		if ($affected > 0) {
			$output->info('Absence: annual/unpaid/special leave now require a replacement.');
		}
	}
}
