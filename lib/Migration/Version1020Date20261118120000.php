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
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The purge of a deleted account (§17) nulls the account where it acted on
 * other people's records, attachments included, and keeps a list of the
 * purges to retry. Earlier purges wrote the uid `deleted_user` there instead,
 * which an account could have: those become null too, unless an account has
 * that uid.
 */
class Version1020Date20261118120000 extends SimpleMigrationStep {
	private const OLD_MARKER = 'deleted_user';
	private const ACTOR_COLUMNS = [
		'absence_request_events' => 'actor_uid',
		'absence_ent_events' => 'actor_uid',
		'absence_attachments' => 'uploader_uid',
	];

	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
	) {
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('absence_purge_retry')) {
			$table = $schema->createTable('absence_purge_retry');
			$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->setPrimaryKey(['uid']);
		}
		$uploader = $schema->getTable('absence_attachments')->getColumn('uploader_uid');
		if ($uploader->getNotnull()) {
			$uploader->setNotnull(false);
		}
		return $schema;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->userManager->userExists(self::OLD_MARKER)) {
			return;
		}
		$cleared = 0;
		foreach (self::ACTOR_COLUMNS as $table => $column) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($table)
				->set($column, $qb->createNamedParameter(null))
				->where($qb->expr()->eq($column, $qb->createNamedParameter(self::OLD_MARKER)));
			$cleared += $qb->executeStatement();
		}
		if ($cleared > 0) {
			$output->info(sprintf('Cleared the actor of %d entries left by deleted accounts', $cleared));
		}
	}
}
