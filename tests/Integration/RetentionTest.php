<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Command\Retention;
use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\RetentionService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The retention settings (§12) on a real database, with the longest period so
 * that only this test's rows are old enough: leave and a contract that ended
 * the day before the cutoff go, the ones that ended on it stay.
 */
class RetentionTest extends TestCase {
	private const OWN = 'itest-retention-own';
	private const LEFT = 'itest-retention-left';
	private const STAYED = 'itest-retention-stayed';
	/** Left long ago, and only the contract dates are left of them */
	private const DATES_ONLY = 'itest-retention-dates-only';
	private const UIDS = [self::OWN, self::LEFT, self::STAYED, self::DATES_ONLY];

	private IDBConnection $db;
	/** @var array{0: int, 1: int} */
	private array $retention = [0, 0];
	private string $cutoff = '';
	private int $old = 0;
	private int $kept = 0;
	private int $leftLeave = 0;
	private int $attachment = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$config = Server::get(ConfigService::class);
		$this->retention = [$config->getLeaveRetentionYears(), $config->getFormerEmployeeRetentionYears()];
		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueInt(ConfigService::APP_ID, ConfigLexicon::KEY_LEAVE_RETENTION, ConfigService::MAX_RETENTION_YEARS);
		$appConfig->setValueInt(ConfigService::APP_ID, ConfigLexicon::KEY_FORMER_EMPLOYEE_RETENTION, ConfigService::MAX_RETENTION_YEARS);

		$this->cutoff = (string)Server::get(RetentionService::class)->leaveCutoff();
		$dayBefore = $this->dayBefore();

		$this->old = $this->request(self::OWN, $dayBefore);
		$this->kept = $this->request(self::OWN, $this->cutoff);
		// Leave of a former employee goes with them, however recent
		$this->leftLeave = $this->request(self::LEFT, '2026-03-03');
		$commentId = $this->insert('absence_comments', ['request_id' => $this->old, 'author_uid' => self::OWN, 'body' => 'itest', 'created_at' => '2026-03-01 09:00:00']);
		$this->insert('absence_comment_mails', ['comment_id' => $commentId, 'request_id' => $this->old, 'recipient_uid' => self::STAYED]);
		$this->insert('absence_request_events', ['request_id' => $this->old, 'actor_uid' => self::OWN, 'event_type' => 'request_created', 'created_at' => '2026-03-01 09:00:00']);
		$this->attachment = $this->insert('absence_attachments', ['request_id' => $this->old, 'uploader_uid' => self::OWN, 'name' => 'note.pdf', 'mime' => 'application/pdf', 'size' => 5, 'created_at' => '2026-03-01 09:00:00']);
		$this->attachments()->newFile((string)$this->attachment, 'itest');

		$this->insert('absence_employments', ['employee_uid' => self::LEFT, 'start_date' => '1990-01-01', 'end_date' => $dayBefore]);
		$this->insert('absence_employments', ['employee_uid' => self::STAYED, 'start_date' => '1990-01-01', 'end_date' => $this->cutoff]);
		$this->insert('absence_employments', ['employee_uid' => self::DATES_ONLY, 'start_date' => '1980-01-01', 'end_date' => '1985-12-31']);
		foreach ([self::LEFT, self::STAYED] as $uid) {
			$entitlement = $this->insert('absence_entitlements', ['employee_uid' => $uid, 'year' => 1995, 'type_id' => 1, 'base_days' => 28, 'created_at' => '1995-01-01 00:00:00', 'updated_at' => '1995-01-01 00:00:00']);
			$this->insert('absence_ent_events', ['entitlement_id' => $entitlement, 'employee_uid' => $uid, 'actor_uid' => 'system', 'field' => 'base_days', 'created_at' => '1995-01-01 00:00:00']);
			$this->insert('absence_periods', ['employee_uid' => $uid, 'valid_from' => '1990-01-01', 'weekdays' => '1,2,3,4,5']);
			$this->insert('absence_emp_history', ['employee_uid' => $uid, 'field' => 'contract', 'detail' => '{}', 'created_at' => '1995-01-01 00:00:00']);
		}
	}

	protected function tearDown(): void {
		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueInt(ConfigService::APP_ID, ConfigLexicon::KEY_LEAVE_RETENTION, $this->retention[0]);
		$appConfig->setValueInt(ConfigService::APP_ID, ConfigLexicon::KEY_FORMER_EMPLOYEE_RETENTION, $this->retention[1]);
		$ids = [$this->old, $this->kept, $this->leftLeave];
		foreach (['absence_comments', 'absence_comment_mails', 'absence_request_events', 'absence_attachments'] as $table) {
			$this->delete($table, 'request_id', $ids, IQueryBuilder::PARAM_INT_ARRAY);
		}
		$this->delete('absence_requests', 'id', $ids, IQueryBuilder::PARAM_INT_ARRAY);
		foreach (['absence_employments', 'absence_entitlements', 'absence_ent_events', 'absence_periods', 'absence_emp_history'] as $table) {
			$this->delete($table, 'employee_uid', self::UIDS, IQueryBuilder::PARAM_STR_ARRAY);
		}
		if ($this->attachments()->fileExists((string)$this->attachment)) {
			$this->attachments()->getFile((string)$this->attachment)->delete();
		}
		parent::tearDown();
	}

	public function testTheDryRunCountsWhatWouldGoAndDeletesNothing(): void {
		$tester = new CommandTester(Server::get(Retention::class));

		self::assertSame(0, $tester->execute(['--dry-run' => true]));

		$output = $tester->getDisplay();
		self::assertStringContainsString('Leave: what ended before ' . $this->cutoff . ' goes.', $output);
		self::assertStringContainsString('Would delete:', $output);
		self::assertStringContainsString('2 requests', $output);
		self::assertStringContainsString('1 attachments', $output);
		self::assertStringContainsString('1 entitlements', $output);
		self::assertStringContainsString('1 former employees', $output);
		self::assertSame(3, $this->rows('absence_requests', 'employee_uid', self::UIDS));
		self::assertSame(3, $this->rows('absence_employments', 'employee_uid', self::UIDS));
		self::assertSame(1, $this->rows('absence_attachments', 'request_id', [$this->old]));
		self::assertTrue($this->attachments()->fileExists((string)$this->attachment));
	}

	public function testLeaveThatEndedBeforeTheCutoffGoesWithEverythingOnIt(): void {
		$counts = Server::get(RetentionService::class)->apply();

		self::assertSame(['requests' => 2, 'attachments' => 1, 'entitlements' => 1, 'people' => 1], $counts);
		self::assertSame([$this->kept], array_map(intval(...), $this->column('absence_requests', 'id', 'employee_uid', self::UIDS)));
		foreach (['absence_comments', 'absence_comment_mails', 'absence_request_events', 'absence_attachments'] as $table) {
			self::assertSame(0, $this->rows($table, 'request_id', [$this->old]), $table);
		}
		self::assertFalse($this->attachments()->fileExists((string)$this->attachment));
	}

	public function testAFormerEmployeeKeepsOnlyTheirContractDatesAndNobodyElseIsTouched(): void {
		$messages = new \ArrayObject();
		Server::get(IEventDispatcher::class)->addListener(CriticalActionPerformedEvent::class, static function (CriticalActionPerformedEvent $event) use ($messages): void {
			$messages->append(vsprintf($event->getLogMessage(), $event->getParameters()));
		});

		Server::get(RetentionService::class)->apply();

		foreach (['absence_entitlements', 'absence_ent_events', 'absence_periods', 'absence_emp_history'] as $table) {
			self::assertSame([self::STAYED], $this->column($table, 'employee_uid', 'employee_uid', [self::LEFT, self::STAYED]), $table);
		}
		self::assertSame(0, $this->rows('absence_requests', 'employee_uid', [self::LEFT]));
		// Without the dates, someone still in the employees group would be a current employee again
		self::assertSame(['1990-01-01', $this->dayBefore()], $this->dates(self::LEFT));
		self::assertSame(['1990-01-01', $this->cutoff], $this->dates(self::STAYED));
		self::assertSame(['1980-01-01', '1985-12-31'], $this->dates(self::DATES_ONLY));
		self::assertContains('Absence retention deleted 2 requests, 1 attachments, 1 entitlements and the data of 1 former employees', $messages->getArrayCopy());
	}

	public function testSomeoneWithOnlyTheirContractDatesLeftIsNotCountedAgain(): void {
		$retention = Server::get(RetentionService::class);
		$retention->apply();

		$none = ['requests' => 0, 'attachments' => 0, 'entitlements' => 0, 'people' => 0];
		self::assertSame($none, $retention->apply(true));
		self::assertSame($none, $retention->apply());
		self::assertSame(3, $this->rows('absence_employments', 'employee_uid', self::UIDS));
	}

	private function dayBefore(): string {
		return (new \DateTimeImmutable($this->cutoff))->modify('-1 day')->format('Y-m-d');
	}

	/**
	 * @return list<mixed> start and end date
	 */
	private function dates(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('start_date', 'end_date')->from('absence_employments')
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($uid)));
		return array_values((array)$qb->executeQuery()->fetch());
	}

	private function request(string $uid, string $end): int {
		$request = new LeaveRequest();
		$request->setEmployeeUid($uid);
		$request->setTypeId(1);
		$request->setStartDate($end);
		$request->setEndDate($end);
		$request->setWorkingDays(1.0);
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		$request->setCreatedAt(new \DateTime('2026-03-01 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2026-03-01 09:00:00'));
		return (int)Server::get(LeaveRequestMapper::class)->insert($request)->getId();
	}

	private function attachments(): ISimpleFolder {
		$appData = Server::get(IAppDataFactory::class)->get(ConfigService::APP_ID);
		try {
			return $appData->getFolder('attachments');
		} catch (\OCP\Files\NotFoundException) {
			return $appData->newFolder('attachments');
		}
	}

	/**
	 * @param array<string, int|string> $values
	 */
	private function insert(string $table, array $values): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table)->values(array_map(static fn (int|string $value) => $qb->createNamedParameter($value), $values));
		$qb->executeStatement();
		return $qb->getLastInsertId();
	}

	/**
	 * @param list<int|string> $values
	 * @return list<mixed>
	 */
	private function column(string $table, string $column, string $where, array $values): array {
		$qb = $this->db->getQueryBuilder();
		$type = is_int($values[0]) ? IQueryBuilder::PARAM_INT_ARRAY : IQueryBuilder::PARAM_STR_ARRAY;
		$qb->select($column)->from($table)->where($qb->expr()->in($where, $qb->createNamedParameter($values, $type)))->orderBy($column);
		return $qb->executeQuery()->fetchFirstColumn();
	}

	/**
	 * @param list<int|string> $values
	 */
	private function rows(string $table, string $where, array $values): int {
		return count($this->column($table, $where, $where, $values));
	}

	/**
	 * @param list<int|string> $values
	 */
	private function delete(string $table, string $column, array $values, int $type): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($values, $type)))->executeStatement();
	}
}
