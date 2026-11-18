<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The free text of the request history (reasons, import notes, decision
 * comments and comments) is for HR alone (§3.7): `hr_detail` holds the entry
 * as HR reads it, `detail` the same entry without that text. Existing entries
 * are split along the formats RequestService and LeaveImportService write.
 */
class Version1021Date20261118120000 extends SimpleMigrationStep {
	private const TABLE = 'absence_request_events';
	private const CHUNK = 1000;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(self::TABLE) || $schema->getTable(self::TABLE)->hasColumn('hr_detail')) {
			return null;
		}
		$schema->getTable(self::TABLE)->addColumn('hr_detail', Types::TEXT, ['notnull' => false]);
		return $schema;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$split = 0;
		$lastId = 0;
		do {
			$rows = $this->unsplitRows($lastId);
			$this->db->beginTransaction();
			try {
				foreach ($rows as $row) {
					$lastId = (int)$row['id'];
					$detail = (string)$row['detail'];
					[$neutral, $hr] = self::split((string)$row['event_type'], $detail);
					if ($hr === null) {
						continue;
					}
					$update = $this->db->getQueryBuilder();
					$update->update(self::TABLE)
						->set('detail', $update->createNamedParameter($neutral))
						->set('hr_detail', $update->createNamedParameter($hr))
						->where($update->expr()->eq('id', $update->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
						->executeStatement();
					$split++;
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		} while (count($rows) === self::CHUNK);
		$output->info(sprintf('Kept the free text of %d history entries for HR', $split));
	}

	/**
	 * The detail everyone reads, and the one HR reads when it differs.
	 *
	 * A quoted text runs to the first closing quote, so a text that holds one
	 * cannot be told apart from what follows it: an entry with a quote left
	 * once its text is taken out goes to HR whole, and everyone else sees the
	 * event without a detail.
	 *
	 * @return array{0: ?string, 1: ?string}
	 */
	public static function split(string $eventType, string $detail): array {
		$neutral = match ($eventType) {
			'request_created' => preg_replace('/ · Reason: “[^”]*”/u', '', $detail, 1),
			'request_imported' => preg_replace('/ · Note: “[^”]*”/u', '', $detail, 1),
			'request_updated', 'request_hr_edited' => preg_replace_callback(
				'/Reason(?:: “[^”]*”| “[^”]*” → “[^”]*”)/u',
				static fn (array $match): string => str_starts_with($match[0], 'Reason:') ? 'Reason added' : 'Reason changed',
				$detail,
				1,
			),
			'request_edited_superseding', 'disability_changed' => $detail,
			// Comments, decision comments, and any event not listed here
			default => null,
		};
		if ($neutral === null || preg_match('/[“”]/u', $neutral) !== 0) {
			return [null, $detail];
		}
		return $neutral === $detail ? [$detail, null] : [$neutral, $detail];
	}

	/**
	 * The next entries that have a detail and no HR detail yet, by id.
	 *
	 * @return list<array{id: int|string, event_type: string, detail: string}>
	 */
	private function unsplitRows(int $afterId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'event_type', 'detail')
			->from(self::TABLE)
			->where($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('detail'))
			->andWhere($qb->expr()->isNull('hr_detail'))
			->orderBy('id', 'ASC')
			->setMaxResults(self::CHUNK);
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}
}
