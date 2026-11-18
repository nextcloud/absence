<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OC\DB\SchemaWrapper;
use OCA\Absence\Migration\Version1021Date20261118120000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The upgrade moves the free text of the stored history to the HR detail
 * (§3.7), against a real database.
 */
class HistoryTextSplitTest extends TestCase {
	/** The request the rows of this test belong to: no such request exists */
	private const REQUEST = 990021;

	private IDBConnection $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
		parent::tearDown();
	}

	public function testEveryBatchIsSplitAndASecondRunChangesNothing(): void {
		$chunk = (new \ReflectionClassConstant(Version1021Date20261118120000::class, 'CHUNK'))->getValue();
		$this->db->beginTransaction();
		for ($i = 0; $i <= $chunk; $i++) {
			$this->insert('comment_added', 'itest comment ' . $i);
		}
		$this->db->commit();
		$this->insert('request_created', 'Annual leave, 2026-03-02 (1 day) · Reason: “itest migraine” · Automatically approved');
		$this->insert('request_updated', 'Reason “itest a ” b” → “itest c”');
		$this->insert('request_hr_edited', 'Dates 2026-03-02 → 2026-03-03');

		$this->migrate();
		$first = $this->rows();
		$this->migrate();

		self::assertSame($first, $this->rows());
		$comments = array_slice($first, 0, $chunk + 1);
		self::assertSame(array_fill(0, $chunk + 1, null), array_column($comments, 'detail'));
		self::assertSame('itest comment ' . $chunk, end($comments)['hr_detail']);
		self::assertSame([
			['detail' => 'Annual leave, 2026-03-02 (1 day) · Automatically approved', 'hr_detail' => 'Annual leave, 2026-03-02 (1 day) · Reason: “itest migraine” · Automatically approved'],
			['detail' => null, 'hr_detail' => 'Reason “itest a ” b” → “itest c”'],
			['detail' => 'Dates 2026-03-02 → 2026-03-03', 'hr_detail' => null],
		], array_slice($first, $chunk + 1));
	}

	private function migrate(): void {
		Server::get(Version1021Date20261118120000::class)->postSchemaChange(
			$this->createMock(IOutput::class),
			fn (): ISchemaWrapper => new SchemaWrapper($this->db),
			[],
		);
	}

	private function insert(string $eventType, string $detail): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('absence_request_events')->values([
			'request_id' => $qb->createNamedParameter(self::REQUEST, IQueryBuilder::PARAM_INT),
			'actor_uid' => $qb->createNamedParameter('itest'),
			'event_type' => $qb->createNamedParameter($eventType),
			'detail' => $qb->createNamedParameter($detail),
			'created_at' => $qb->createNamedParameter(new \DateTime('2026-03-01 09:00:00'), IQueryBuilder::PARAM_DATETIME_MUTABLE),
		])->executeStatement();
	}

	/**
	 * @return list<array{detail: ?string, hr_detail: ?string}> in the order they were written
	 */
	private function rows(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('detail', 'hr_detail')
			->from('absence_request_events')
			->where($qb->expr()->eq('request_id', $qb->createNamedParameter(self::REQUEST, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	private function cleanUp(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('absence_request_events')
			->where($qb->expr()->eq('request_id', $qb->createNamedParameter(self::REQUEST, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
