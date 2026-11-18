<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Migration\Version1020Date20261118120000;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The `deleted_user` that earlier purges wrote as the actor becomes null,
 * unless an account has that uid.
 */
class DeletedActorMigrationTest extends TestCase {
	private const MARKER = 'deleted_user';
	/** No request or entitlement has these ids: the rows stand alone */
	private const REQUEST = 990021;
	private const ENTITLEMENT = 990021;

	private IDBConnection $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->insert('absence_request_events', ['request_id' => self::REQUEST, 'actor_uid' => self::MARKER, 'event_type' => 'request_approved', 'created_at' => '2026-03-01 09:00:00']);
		$this->insert('absence_ent_events', ['entitlement_id' => self::ENTITLEMENT, 'employee_uid' => 'itest-migration', 'actor_uid' => self::MARKER, 'field' => 'base_days', 'old_value' => 20, 'new_value' => 25, 'created_at' => '2026-03-01 09:00:00']);
		$this->insert('absence_attachments', ['request_id' => self::REQUEST, 'uploader_uid' => self::MARKER, 'name' => 'itest.pdf', 'mime' => 'application/pdf', 'size' => 1, 'created_at' => '2026-03-01 09:00:00']);
	}

	protected function tearDown(): void {
		Server::get(IUserManager::class)->get(self::MARKER)?->delete();
		$this->deleteWhere('absence_request_events', 'request_id', self::REQUEST);
		$this->deleteWhere('absence_ent_events', 'entitlement_id', self::ENTITLEMENT);
		$this->deleteWhere('absence_attachments', 'request_id', self::REQUEST);
		parent::tearDown();
	}

	public function testTheOldMarkerBecomesNull(): void {
		$this->migrate();

		self::assertSame([null, null, null], $this->actors());
	}

	public function testTheMarkerStaysWhenAnAccountHasThatUid(): void {
		Server::get(IUserManager::class)->createUser(self::MARKER, 'Itest-Password-marker-1');

		$this->migrate();

		self::assertSame([self::MARKER, self::MARKER, self::MARKER], $this->actors());
	}

	private function migrate(): void {
		Server::get(Version1020Date20261118120000::class)
			->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);
	}

	/**
	 * @return list<?string> the actor of the request event, the entitlement event and the attachment
	 */
	private function actors(): array {
		return [
			$this->column('absence_request_events', 'actor_uid', 'request_id', self::REQUEST),
			$this->column('absence_ent_events', 'actor_uid', 'entitlement_id', self::ENTITLEMENT),
			$this->column('absence_attachments', 'uploader_uid', 'request_id', self::REQUEST),
		];
	}

	/**
	 * @param array<string, int|string> $values
	 */
	private function insert(string $table, array $values): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table)->values(array_map(static fn (int|string $value) => $qb->createNamedParameter($value), $values));
		$qb->executeStatement();
	}

	private function column(string $table, string $column, string $where, int $value): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select($column)->from($table)->where($qb->expr()->eq($where, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
		$values = $qb->executeQuery()->fetchFirstColumn();
		self::assertCount(1, $values);
		return $values[0];
	}

	private function deleteWhere(string $table, string $column, int $value): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
