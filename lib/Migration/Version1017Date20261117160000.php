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
 * The comment e-mails waiting to be sent: one row per comment and recipient,
 * mailed together once the request's thread has gone quiet (§11).
 */
class Version1017Date20261117160000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('absence_comment_mails')) {
			return null;
		}
		$table = $schema->createTable('absence_comment_mails');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('comment_id', Types::BIGINT, ['notnull' => true]);
		// Denormalised from the comment, to find the threads with mail waiting
		$table->addColumn('request_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('recipient_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['request_id'], 'absence_cmail_req');
		$table->addIndex(['recipient_uid'], 'absence_cmail_uid');
		return $schema;
	}
}
