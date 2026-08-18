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
 * Adds the `hr_only` flag to leave types (§5.7): a confidential category —
 * maternity leave, a medical work prohibition, a doctor's note — that HR
 * records and that only HR may see. To everyone else, including the line
 * manager and the employee's own views, such an absence renders as a neutral
 * "Absent" with dates but no category.
 */
class Version1006Date20260818170000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('absence_leave_types');
		if ($table->hasColumn('hr_only')) {
			return null;
		}
		$table->addColumn('hr_only', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		return $schema;
	}
}
