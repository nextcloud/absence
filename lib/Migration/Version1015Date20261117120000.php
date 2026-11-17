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
 * History entries and comments remember that they were written while their
 * request was confidential (§5.7), so they stay with HR when the request or
 * its type is reclassified. The rows of requests that are confidential at the
 * time of the upgrade get the flag.
 */
class Version1015Date20261117120000 extends SimpleMigrationStep {
	private const TABLES = ['absence_request_events', 'absence_comments'];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;
		foreach (self::TABLES as $name) {
			if (!$schema->hasTable($name) || $schema->getTable($name)->hasColumn('confidential')) {
				continue;
			}
			// Nullable: on Oracle a NOT NULL boolean column cannot hold false
			$schema->getTable($name)->addColumn('confidential', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$types = $this->db->getQueryBuilder();
		$types->select('id')
			->from('absence_leave_types')
			->where($types->expr()->eq('hr_only', $types->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		$result = $types->executeQuery();
		$typeIds = array_map(intval(...), $result->fetchFirstColumn());
		$result->closeCursor();
		if ($typeIds === []) {
			return;
		}

		foreach (self::TABLES as $name) {
			$update = $this->db->getQueryBuilder();
			$requests = $this->db->getQueryBuilder();
			$requests->select('id')
				->from('absence_requests')
				->where($requests->expr()->in('type_id', $update->createNamedParameter($typeIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$update->update($name)
				->set('confidential', $update->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				->where($update->expr()->in('request_id', $update->createFunction($requests->getSQL())));
			$output->info(sprintf('Marked %d rows of %s as confidential', $update->executeStatement(), $name));
		}
	}
}
