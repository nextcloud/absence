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
 * The request and entitlement histories (§3.7, §3.7b) may have no actor, as the
 * holiday and employee histories already do: a change made from occ has none.
 */
class Version1011Date20261110120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;
		foreach (['absence_request_events', 'absence_entitlement_events'] as $name) {
			if (!$schema->hasTable($name) || !$schema->getTable($name)->hasColumn('actor_uid')) {
				continue;
			}
			$column = $schema->getTable($name)->getColumn('actor_uid');
			if ($column->getNotnull()) {
				$column->setNotnull(false);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}
}
