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
 * Adds the `disability` flag to leave requests (§5.8): HR can mark annual leave
 * as disability-related (e.g. the additional statutory entitlement). The flag
 * is set and seen by HR alone — serialization withholds it from everyone else.
 */
class Version1007Date20260818200000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('absence_requests');
		if ($table->hasColumn('disability')) {
			return null;
		}
		$table->addColumn('disability', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		return $schema;
	}
}
