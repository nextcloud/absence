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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the `employee_requestable` flag to leave types and marks the seeded `sick`
 * type as HR-recorded (not self-requestable). Guarded, so a no-op on fresh installs.
 */
class Version1002Date20260711120000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('absence_leave_types')) {
			return null;
		}
		$table = $schema->getTable('absence_leave_types');
		if ($table->hasColumn('employee_requestable')) {
			return null;
		}
		$table->addColumn('employee_requestable', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
		return $schema;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Sick leave is recorded by HR, not self-requested. Only touch the seeded row.
		$qb = $this->db->getQueryBuilder();
		$qb->update('absence_leave_types')
			->set('employee_requestable', $qb->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('key', $qb->createNamedParameter('sick')));
		$affected = $qb->executeStatement();
		if ($affected > 0) {
			$output->info('Absence: sick leave marked as HR-recorded (not self-requestable).');
		}
	}
}
