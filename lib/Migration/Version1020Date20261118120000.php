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
 * The purges of deleted accounts (§17) that failed and are to be retried.
 */
class Version1020Date20261118120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('absence_purge_retry')) {
			return null;
		}
		$table = $schema->createTable('absence_purge_retry');
		$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->setPrimaryKey(['uid']);
		return $schema;
	}
}
