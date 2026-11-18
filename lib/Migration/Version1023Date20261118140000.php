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
 * Two free-text columns that no screen reads go (§3.1, §3.4): the note of a
 * request, and the note on an entitlement, which the entitlement history and
 * the activity keep for each adjustment.
 */
class Version1023Date20261118140000 extends SimpleMigrationStep {
	private const COLUMNS = [
		'absence_requests' => 'attachment_note',
		'absence_entitlements' => 'adjustment_note',
	];

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;
		foreach (self::COLUMNS as $name => $column) {
			if ($schema->hasTable($name) && $schema->getTable($name)->hasColumn($column)) {
				$schema->getTable($name)->dropColumn($column);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}
}
