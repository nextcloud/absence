<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drops the three tables the previous step copied to their shorter names. A
 * table is only dropped when its new copy holds at least as many rows;
 * otherwise the upgrade stops and the old table keeps its rows.
 */
class Version1014Date20261111140000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;
		foreach (Version1013Date20261111130000::RENAMED as [$old, $new]) {
			if (!$schema->hasTable($old)) {
				continue;
			}
			// A new install reads every step before writing any table: nothing to count
			$oldRows = $this->db->tableExists($old) ? $this->rows($old) : 0;
			$newRows = $this->db->tableExists($new) ? $this->rows($new) : 0;
			if ($newRows < $oldRows) {
				throw new \RuntimeException(sprintf(
					'Table %s holds %d rows but its copy %s only %d: not all rows were copied, so it is not dropped.',
					$old, $oldRows, $new, $newRows,
				));
			}
			$schema->dropTable($old);
			$changed = true;
		}
		return $changed ? $schema : null;
	}

	private function rows(string $table): int {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($qb->func()->count('*'))->from($table)->executeQuery();
		$rows = (int)$result->fetchOne();
		$result->closeCursor();
		return $rows;
	}
}
