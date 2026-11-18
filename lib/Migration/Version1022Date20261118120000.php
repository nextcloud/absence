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
 * Whether the stored file of an attachment is encrypted (§3.8). The files
 * stored before are plaintext until EncryptAttachmentsJob has encrypted them.
 */
class Version1022Date20261118120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('absence_attachments');
		if ($table->hasColumn('encrypted')) {
			return null;
		}
		$table->addColumn('encrypted', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		return $schema;
	}
}
