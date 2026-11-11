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
 * Drops the three tables the previous step copied to their shorter names.
 */
class Version1014Date20261111140000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;
		foreach (Version1013Date20261111130000::RENAMED as [$old]) {
			if ($schema->hasTable($old)) {
				$schema->dropTable($old);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}
}
