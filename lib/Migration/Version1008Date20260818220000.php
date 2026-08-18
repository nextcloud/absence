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
 * Adds `absence_attachments` (§3.8): files attached to a leave request — the
 * doctor's note above all. Metadata lives here; the bytes live in the app's
 * own appdata storage (never in anybody's Files), so visibility is enforced
 * by the API alone: HR and the employee, never the manager or colleagues.
 */
class Version1008Date20260818220000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('absence_attachments')) {
			return null;
		}

		$table = $schema->createTable('absence_attachments');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('request_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('uploader_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('mime', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => 'application/octet-stream']);
		$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['request_id'], 'absence_att_req_idx');

		return $schema;
	}
}
