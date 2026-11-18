<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Db\Attachment;
use OCA\Absence\Db\AttachmentMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Db\RequestComment;
use OCA\Absence\Db\RequestCommentMapper;
use OCA\Absence\Db\RequestEvent;
use OCA\Absence\Db\RequestEventMapper;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\UserMigration\AbsenceMigrator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use OCP\UserMigration\IExportDestination;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * What the personal data export (§17) holds for an employee, against a real
 * server: what they see in the app, and nothing the API withholds from them.
 */
class AbsenceMigratorTest extends TestCase {
	private const EMPLOYEE = 'itest-export-employee';
	private const HR = 'itest-export-hr';

	private IUser $user;
	private int $open = 0;
	private int $confidential = 0;
	/** @var array<string, string> path => content */
	private array $files = [];

	protected function setUp(): void {
		parent::setUp();
		$users = Server::get(IUserManager::class);
		$this->user = $users->createUser(self::EMPLOYEE, 'itest-' . bin2hex(random_bytes(8)));
		$hrOnly = Server::get(LeaveTypeMapper::class)->hrOnlyTypeIds();
		$annual = 0;
		foreach (Server::get(LeaveTypeMapper::class)->findAll() as $type) {
			if ($type->getKey() === 'annual') {
				$annual = (int)$type->getId();
			}
		}
		self::assertNotSame([], $hrOnly, 'The seeded confidential types are missing');

		$this->open = $this->request($annual, 'Family visit', true);
		$this->comment($this->open, 'See you in January', false);
		$this->comment($this->open, 'Written while the type was confidential', true);
		$this->event($this->open, 'request_created', false);
		$this->event($this->open, 'disability_changed', false);
		$this->attach($this->open, 'ticket.pdf', 'ticket');

		$this->confidential = $this->request($hrOnly[0], 'Medical reason', false);
		$this->comment($this->confidential, 'HR note', false);
		$this->event($this->confidential, 'request_created', false);
		$this->attach($this->confidential, 'certificate.pdf', 'certificate');

		$this->files = [];
		$destination = $this->createMock(IExportDestination::class);
		$destination->method('addFileContents')->willReturnCallback(function (string $path, string $content): void {
			$this->files[$path] = $content;
		});
		Server::get(AbsenceMigrator::class)->export($this->user, $destination, new BufferedOutput());
	}

	protected function tearDown(): void {
		// The account's purge (§17) takes its requests, comments, history and attachments
		$this->user->delete();
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete('absence_requests')
			->where($qb->expr()->in('id', $qb->createNamedParameter([$this->open, $this->confidential], IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
		parent::tearDown();
	}

	/**
	 * @return array<int, array<string, mixed>> by request id
	 */
	private function exportedRequests(): array {
		$requests = [];
		foreach (json_decode($this->files['absence/requests.json'], true, 512, JSON_THROW_ON_ERROR) as $request) {
			$requests[$request['id']] = $request;
		}
		return $requests;
	}

	public function testAConfidentialAbsenceIsTheBareFactAsInTheApp(): void {
		$request = $this->exportedRequests()[$this->confidential];

		self::assertSame(['2026-03-02', '2026-03-03', LeaveRequest::STATUS_APPROVED], [$request['startDate'], $request['endDate'], $request['status']]);
		foreach (['typeId', 'reason', 'decidedBy', 'decisionComment', 'disability'] as $withheld) {
			self::assertNull($request[$withheld], $withheld);
		}
		self::assertSame([[], [], []], [$request['comments'], $request['history'], $request['attachments']]);
		self::assertStringNotContainsString('Medical reason', $this->files['absence/requests.json']);
		self::assertStringNotContainsString('HR note', $this->files['absence/requests.json']);
	}

	public function testOnlyTheAttachmentsTheEmployeeMaySeeAreInTheArchive(): void {
		$attachments = array_filter($this->files, static fn (string $path): bool => str_starts_with($path, 'absence/attachments/'), ARRAY_FILTER_USE_KEY);

		self::assertSame(['ticket'], array_values($attachments));
		self::assertStringEndsWith('/ticket.pdf', (string)array_key_first($attachments));
	}

	public function testTheEmployeeGetsWhatTheySeeOfTheirOwnRequest(): void {
		$request = $this->exportedRequests()[$this->open];

		self::assertSame('Family visit', $request['reason']);
		self::assertNull($request['disability']);
		self::assertSame(['See you in January'], array_column($request['comments'], 'body'));
		self::assertSame(['request_created'], array_column($request['history'], 'eventType'));
		self::assertSame(['ticket.pdf'], array_column($request['attachments'], 'name'));
		self::assertArrayNotHasKey('canModify', $request);
	}

	public function testNoConfidentialCategoryIsNamed(): void {
		$hrOnly = Server::get(LeaveTypeMapper::class)->hrOnlyTypeIds();
		$types = json_decode($this->files['absence/leave-types.json'], true, 512, JSON_THROW_ON_ERROR);
		$balances = json_decode($this->files['absence/balances.json'], true, 512, JSON_THROW_ON_ERROR);

		self::assertSame([], array_intersect(array_column($types, 'id'), $hrOnly));
		self::assertSame([], array_intersect(array_column($balances, 'typeId'), $hrOnly));
		self::assertContains(2026, array_column($balances, 'year'));
	}

	private function request(int $typeId, string $reason, bool $disability): int {
		$request = new LeaveRequest();
		$request->setEmployeeUid(self::EMPLOYEE);
		$request->setTypeId($typeId);
		$request->setStartDate('2026-03-02');
		$request->setEndDate('2026-03-03');
		$request->setWorkingDays(2.0);
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		$request->setReason($reason);
		$request->setDecidedBy(self::HR);
		$request->setDisability($disability);
		$request->setCreatedAt(new \DateTime('2026-03-01 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2026-03-01 09:00:00'));
		return (int)Server::get(LeaveRequestMapper::class)->insert($request)->getId();
	}

	private function comment(int $requestId, string $body, bool $confidential): void {
		$comment = new RequestComment();
		$comment->setRequestId($requestId);
		$comment->setAuthorUid(self::HR);
		$comment->setBody($body);
		$comment->setConfidential($confidential);
		$comment->setCreatedAt(new \DateTime('2026-03-01 10:00:00'));
		Server::get(RequestCommentMapper::class)->insert($comment);
	}

	private function event(int $requestId, string $type, bool $confidential): void {
		$event = new RequestEvent();
		$event->setRequestId($requestId);
		$event->setActorUid(self::HR);
		$event->setEventType($type);
		$event->setConfidential($confidential);
		$event->setCreatedAt(new \DateTime('2026-03-01 09:00:00'));
		Server::get(RequestEventMapper::class)->insert($event);
	}

	private function attach(int $requestId, string $name, string $content): void {
		$attachment = new Attachment();
		$attachment->setRequestId($requestId);
		$attachment->setUploaderUid(self::HR);
		$attachment->setName($name);
		$attachment->setMime('application/pdf');
		$attachment->setSize(strlen($content));
		$attachment->setCreatedAt(new \DateTime('2026-03-01 09:00:00'));
		$attachment = Server::get(AttachmentMapper::class)->insert($attachment);
		$this->attachments()->newFile((string)$attachment->getId(), $content);
	}

	private function attachments(): ISimpleFolder {
		$appData = Server::get(IAppDataFactory::class)->get(ConfigService::APP_ID);
		try {
			return $appData->getFolder('attachments');
		} catch (NotFoundException) {
			return $appData->newFolder('attachments');
		}
	}
}
