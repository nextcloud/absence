<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\Attachment;
use OCA\Absence\Db\AttachmentMapper;
use OCA\Absence\Db\CommentMailMapper;
use OCA\Absence\Db\Employment;
use OCA\Absence\Db\EmploymentMapper;
use OCA\Absence\Db\Entitlement;
use OCA\Absence\Db\EntitlementEventMapper;
use OCA\Absence\Db\EntitlementMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\RequestCommentMapper;
use OCA\Absence\Db\RequestEventMapper;
use OCA\Absence\Service\AttachmentService;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeService;
use OCA\Absence\Service\NotificationService;
use OCA\Absence\Service\RetentionService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RetentionServiceTest extends TestCase {
	private ConfigService&MockObject $config;
	private ClockService&MockObject $clock;
	private LeaveRequestMapper&MockObject $requestMapper;
	private RequestCommentMapper&MockObject $commentMapper;
	private RequestEventMapper&MockObject $eventMapper;
	private CommentMailMapper&MockObject $commentMailMapper;
	private AttachmentMapper&MockObject $attachmentMapper;
	private AttachmentService&MockObject $attachments;
	private NotificationService&MockObject $notifications;
	private EntitlementMapper&MockObject $entitlementMapper;
	private EntitlementEventMapper&MockObject $entitlementEventMapper;
	private EmploymentMapper&MockObject $employmentMapper;
	private EmployeeService&MockObject $employees;
	private IEventDispatcher&MockObject $dispatcher;
	private RetentionService $service;
	private string $today = '2026-11-18';

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(ConfigService::class);
		$this->clock = $this->createMock(ClockService::class);
		$this->clock->method('serverToday')->willReturnCallback(fn (): string => $this->today);
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);
		$this->commentMapper = $this->createMock(RequestCommentMapper::class);
		$this->eventMapper = $this->createMock(RequestEventMapper::class);
		$this->commentMailMapper = $this->createMock(CommentMailMapper::class);
		$this->attachmentMapper = $this->createMock(AttachmentMapper::class);
		$this->attachments = $this->createMock(AttachmentService::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->entitlementMapper = $this->createMock(EntitlementMapper::class);
		$this->entitlementEventMapper = $this->createMock(EntitlementEventMapper::class);
		$this->employmentMapper = $this->createMock(EmploymentMapper::class);
		$this->employees = $this->createMock(EmployeeService::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->service = new RetentionService(
			$this->config,
			$this->clock,
			$this->requestMapper,
			$this->commentMapper,
			$this->eventMapper,
			$this->commentMailMapper,
			$this->attachmentMapper,
			$this->attachments,
			$this->notifications,
			$this->entitlementMapper,
			$this->entitlementEventMapper,
			$this->employmentMapper,
			$this->employees,
			$this->createMock(IDBConnection::class),
			$this->dispatcher,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function retention(int $leaveYears, int $formerYears): void {
		$this->config->method('getLeaveRetentionYears')->willReturn($leaveYears);
		$this->config->method('getFormerEmployeeRetentionYears')->willReturn($formerYears);
	}

	private function request(int $id, string $uid = 'alice'): LeaveRequest {
		$request = new LeaveRequest();
		$request->setId($id);
		$request->setEmployeeUid($uid);
		return $request;
	}

	/**
	 * @return list<LeaveRequest>
	 */
	private function requests(int $firstId, int $count): array {
		return array_map(fn (int $id): LeaveRequest => $this->request($id), range($firstId, $firstId + $count - 1));
	}

	private function employment(int $id, string $uid): Employment {
		$employment = new Employment();
		$employment->setId($id);
		$employment->setEmployeeUid($uid);
		return $employment;
	}

	public function testNothingHappensWhileBothSettingsAreZero(): void {
		$this->retention(0, 0);
		$this->employmentMapper->expects(self::never())->method($this->anything());
		$this->requestMapper->expects(self::never())->method($this->anything());
		$this->attachments->expects(self::never())->method($this->anything());
		$this->dispatcher->expects(self::never())->method('dispatchTyped');

		self::assertSame(['requests' => 0, 'attachments' => 0, 'entitlements' => 0, 'people' => 0], $this->service->apply());
		self::assertNull($this->service->leaveCutoff());
		self::assertNull($this->service->formerEmployeeCutoff());
	}

	public static function cutoffs(): array {
		return [
			'the same day, years back' => ['2026-11-18', 3, '2023-11-18'],
			'new year' => ['2027-01-01', 1, '2026-01-01'],
			'leap day into a year without one' => ['2028-02-29', 1, '2027-02-28'],
			'leap day into a leap year' => ['2028-02-29', 4, '2024-02-29'],
			'the longest period' => ['2026-11-18', 30, '1996-11-18'],
		];
	}

	#[DataProvider('cutoffs')]
	public function testLeaveGoesOnceTheFullYearsHavePassedSinceItsLastDay(string $today, int $years, string $cutoff): void {
		$this->today = $today;
		$this->retention($years, $years);

		self::assertSame($cutoff, $this->service->leaveCutoff());
		self::assertSame($cutoff, $this->service->formerEmployeeCutoff());
	}

	public function testOldLeaveGoesWithEverythingOnIt(): void {
		$this->retention(3, 0);
		$this->employmentMapper->expects(self::never())->method($this->anything());
		$this->requestMapper->expects(self::once())->method('findEndedBefore')
			->with('2023-11-18', 0, RetentionService::BATCH_SIZE)
			->willReturn([$this->request(4), $this->request(9, 'bob')]);
		$this->attachmentMapper->method('findForRequests')->with([4, 9])->willReturn([new Attachment()]);
		$this->notifications->expects(self::exactly(2))->method('dismiss');
		$this->attachments->expects(self::once())->method('purgeForRequests')->with([4, 9]);
		$this->commentMapper->expects(self::once())->method('deleteForRequests')->with([4, 9]);
		$this->eventMapper->expects(self::once())->method('deleteForRequests')->with([4, 9]);
		$this->commentMailMapper->expects(self::once())->method('deleteForRequests')->with([4, 9]);
		$this->requestMapper->expects(self::once())->method('deleteByIds')->with([4, 9]);
		$this->dispatcher->expects(self::once())->method('dispatchTyped')->with($this->callback(
			// Counts only: no uid, no date, no request id
			static fn (CriticalActionPerformedEvent $event): bool => $event->getParameters() === ['2', '1', '0', '0'],
		));

		self::assertSame(['requests' => 2, 'attachments' => 1, 'entitlements' => 0, 'people' => 0], $this->service->apply());
	}

	public function testLeaveGoesInBatchesUntilAPageIsNotFull(): void {
		$this->retention(1, 0);
		$full = $this->requests(1, RetentionService::BATCH_SIZE);
		$this->requestMapper->expects(self::exactly(2))->method('findEndedBefore')
			->willReturnCallback(fn (string $cutoff, int $afterId): array => match ($afterId) {
				0 => $full,
				RetentionService::BATCH_SIZE => [$this->request(500)],
			});
		$deleted = [];
		$this->requestMapper->expects(self::exactly(2))->method('deleteByIds')
			->willReturnCallback(static function (array $ids) use (&$deleted): void {
				$deleted[] = count($ids);
			});

		self::assertSame(RetentionService::BATCH_SIZE + 1, $this->service->apply()['requests']);
		self::assertSame([RetentionService::BATCH_SIZE, 1], $deleted);
	}

	public function testTheJobStopsAfterItsBatchesAndTheNextRunGoesOn(): void {
		$this->retention(1, 0);
		$this->requestMapper->expects(self::exactly(2))->method('findEndedBefore')
			->willReturnCallback(fn (string $cutoff, int $afterId): array => $this->requests($afterId + 1, RetentionService::BATCH_SIZE));

		self::assertSame(2 * RetentionService::BATCH_SIZE, $this->service->apply(maxBatches: 2)['requests']);
	}

	public function testADryRunCountsAndDeletesNothing(): void {
		$this->retention(2, 5);
		$this->employmentMapper->method('findEndedBefore')->with('2021-11-18', 0, RetentionService::BATCH_SIZE)
			->willReturn([$this->employment(3, 'carol')]);
		$this->requestMapper->method('findAllForEmployee')->with('carol')->willReturn([$this->request(7, 'carol')]);
		$this->entitlementMapper->method('findForEmployee')->with('carol')->willReturn([new Entitlement(), new Entitlement()]);
		$this->requestMapper->method('findEndedBefore')->with('2024-11-18', 0, RetentionService::BATCH_SIZE)
			->willReturn([$this->request(7, 'carol'), $this->request(8)]);
		$this->attachmentMapper->method('findForRequests')->willReturnCallback(
			static fn (array $ids): array => $ids === [7] ? [new Attachment(), new Attachment()] : [],
		);
		foreach ([$this->commentMapper, $this->eventMapper, $this->commentMailMapper] as $mapper) {
			$mapper->expects(self::never())->method('deleteForRequests');
		}
		$this->requestMapper->expects(self::never())->method('deleteByIds');
		$this->attachments->expects(self::never())->method($this->anything());
		$this->notifications->expects(self::never())->method($this->anything());
		$this->entitlementMapper->expects(self::never())->method('deleteForEmployee');
		$this->entitlementEventMapper->expects(self::never())->method($this->anything());
		$this->employees->expects(self::never())->method($this->anything());
		$this->dispatcher->expects(self::never())->method('dispatchTyped');

		// Carol's request 7 is old leave too, and counted once
		self::assertSame(['requests' => 2, 'attachments' => 2, 'entitlements' => 2, 'people' => 1], $this->service->apply(true));
	}

	public function testAFormerEmployeeLosesAllTheirData(): void {
		$this->retention(0, 5);
		$this->requestMapper->expects(self::never())->method('findEndedBefore');
		$this->employmentMapper->method('findEndedBefore')->willReturn([$this->employment(3, 'carol')]);
		$this->requestMapper->method('findAllForEmployee')->with('carol')->willReturn([$this->request(7, 'carol'), $this->request(12, 'carol')]);
		$this->entitlementMapper->method('findForEmployee')->with('carol')->willReturn([new Entitlement()]);
		$this->attachments->expects(self::once())->method('purgeForRequests')->with([7, 12]);
		$this->requestMapper->expects(self::once())->method('deleteByIds')->with([7, 12]);
		$this->entitlementMapper->expects(self::once())->method('deleteForEmployee')->with('carol');
		$this->entitlementEventMapper->expects(self::once())->method('deleteForEmployee')->with('carol');
		$this->employees->expects(self::once())->method('purge')->with('carol');

		self::assertSame(['requests' => 2, 'attachments' => 0, 'entitlements' => 1, 'people' => 1], $this->service->apply());
	}

	public function testNothingToDeleteWritesNoAuditEntry(): void {
		$this->retention(3, 3);
		$this->employmentMapper->method('findEndedBefore')->willReturn([]);
		$this->requestMapper->method('findEndedBefore')->willReturn([]);
		$this->dispatcher->expects(self::never())->method('dispatchTyped');

		self::assertSame(0, array_sum($this->service->apply()));
	}
}
