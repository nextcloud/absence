<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use Closure;
use Doctrine\DBAL\Types\Type;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Days are kept as `YYYY-MM-DD` text, like the out-of-office days of the server
 * (`dav_absence`). A DATE column comes back with a time part on Oracle, and the
 * app reads, compares and sends these values as text everywhere.
 */
class Version1012Date20261111120000 extends SimpleMigrationStep {
	private const DAYS = [
		'absence_requests' => ['start_date', 'end_date'],
		'absence_holidays' => ['date'],
		'absence_employments' => ['start_date', 'end_date'],
		'absence_contract_periods' => ['valid_from'],
	];

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;
		foreach (self::DAYS as $name => $columns) {
			if (!$schema->hasTable($name)) {
				continue;
			}
			$table = $schema->getTable($name);
			foreach ($columns as $columnName) {
				if (!$table->hasColumn($columnName)) {
					continue;
				}
				$column = $table->getColumn($columnName);
				// Nextcloud 35 and later hand out their own column, with an enum
				// as type; Nextcloud 34 hands out the Doctrine one.
				$type = $column->getType();
				$wrapped = $type instanceof \BackedEnum;
				if (($wrapped ? $type->value : Type::lookupName($type)) !== Types::STRING) {
					$column->setType($wrapped ? Types::STRING : Type::getType(Types::STRING));
					$column->setLength(10);
					$changed = true;
				}
			}
		}
		return $changed ? $schema : null;
	}
}
