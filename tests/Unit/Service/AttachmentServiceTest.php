<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\Attachment;
use OCA\Absence\Db\AttachmentMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\AttachmentService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Tests\Unit\ClockMockTrait;
use OCA\Absence\Tests\Unit\L10nMockTrait;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Attachments carry medical documents, so the permission matrix is the test
 * that matters: HR and the employee, never the manager; nothing at all on
 * confidential requests.
 */
class AttachmentServiceTest extends TestCase {
	use ClockMockTrait;
	use L10nMockTrait;

	private AttachmentMapper&MockObject $attachmentMapper;
	private LeaveRequestMapper&MockObject $requestMapper;
	private PermissionService&MockObject $permission;
	private ISimpleFolder&MockObject $folder;
	private LoggerInterface&MockObject $logger;
	private IEventDispatcher&MockObject $dispatcher;
	private AttachmentService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->attachmentMapper = $this->createMock(AttachmentMapper::class);
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);
		$this->permission = $this->createMock(PermissionService::class);
		$this->folder = $this->createMock(ISimpleFolder::class);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($this->folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->service = new AttachmentService(
			$this->attachmentMapper,
			$this->requestMapper,
			$this->permission,
			$factory,
			$this->clockAtRealTime(),
			$this->l10nMock(),
			$this->logger,
			$this->dispatcher,
		);
	}

	private function request(string $status = LeaveRequest::STATUS_APPROVED, bool $hrOnly = false): LeaveRequest {
		$request = new LeaveRequest();
		$request->setId(5);
		$request->setEmployeeUid('emp');
		$request->setTypeId(2);
		$request->setStatus($status);
		$this->requestMapper->method('find')->willReturn($request);
		$this->permission->method('isHrOnlyRequest')->willReturn($hrOnly);
		return $request;
	}

	private function upload(): array {
		$tmp = tempnam(sys_get_temp_dir(), 'att');
		file_put_contents($tmp, 'doctor says rest');
		return ['name' => 'note.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'size' => 16, 'error' => \UPLOAD_ERR_OK];
	}

	public function testTheEmployeeAttachesToTheirOwnSickRecord(): void {
		$this->request();
		$this->permission->method('isHr')->willReturn(false);
		$this->attachmentMapper->method('countForRequest')->willReturn(0);
		$this->attachmentMapper->method('insert')->willReturnCallback(static function (Attachment $a): Attachment {
			$a->setId(77);
			return $a;
		});
		$this->folder->expects(self::once())->method('newFile')->with('77', 'doctor says rest');

		$attachment = $this->service->upload('emp', 5, $this->upload());

		self::assertSame('note.pdf', $attachment->getName());
		self::assertSame('emp', $attachment->getUploaderUid());
	}

	public function testTheManagerMayNeitherSeeNorAttach(): void {
		$request = $this->request();
		$this->permission->method('isHr')->willReturn(false);

		self::assertFalse($this->service->canSee('boss', $request));
		self::assertFalse($this->service->canAttach('boss', $request));
		self::assertSame([], $this->service->listForRequest('boss', 5));
	}

	public function testConfidentialRequestsHideAttachmentsEvenFromTheEmployee(): void {
		$request = $this->request(hrOnly: true);
		$this->permission->method('isHr')->willReturnCallback(
			static fn (string $uid): bool => $uid === 'hr',
		);

		self::assertFalse($this->service->canSee('emp', $request));
		self::assertFalse($this->service->canAttach('emp', $request));
		self::assertTrue($this->service->canSee('hr', $request));
		self::assertTrue($this->service->canAttach('hr', $request));
	}

	public function testNoAttachingToClosedRequests(): void {
		$request = $this->request(LeaveRequest::STATUS_REJECTED);
		$this->permission->method('isHr')->willReturn(false);

		self::assertFalse($this->service->canAttach('emp', $request));
	}

	public function testOversizedUploadsAreRejected(): void {
		$this->request();
		$this->permission->method('isHr')->willReturn(true);
		$upload = $this->upload();
		$upload['size'] = AttachmentService::MAX_SIZE_BYTES + 1;

		$this->expectException(ValidationException::class);
		$this->service->upload('hr', 5, $upload);
	}

	public function testTheAttachmentCapHolds(): void {
		$this->request();
		$this->permission->method('isHr')->willReturn(true);
		$this->attachmentMapper->method('countForRequest')->willReturn(AttachmentService::MAX_PER_REQUEST);

		$this->expectException(ValidationException::class);
		$this->service->upload('hr', 5, $this->upload());
	}

	public function testARowIsNotKeptWhenStoringTheBytesFails(): void {
		$this->request();
		$this->permission->method('isHr')->willReturn(true);
		$this->attachmentMapper->method('countForRequest')->willReturn(0);
		$this->attachmentMapper->method('insert')->willReturnCallback(static function (Attachment $a): Attachment {
			$a->setId(78);
			return $a;
		});
		$this->folder->method('newFile')->willThrowException(new \RuntimeException('disk full'));
		$this->attachmentMapper->expects(self::once())->method('delete');

		$this->expectException(ValidationException::class);
		$this->service->upload('hr', 5, $this->upload());
	}

	private function attachment(string $uploader): Attachment {
		$attachment = new Attachment();
		$attachment->setId(9);
		$attachment->setRequestId(5);
		$attachment->setUploaderUid($uploader);
		$this->attachmentMapper->method('find')->willReturn($attachment);
		return $attachment;
	}

	public function testOnlyHrOrTheUploaderMayDelete(): void {
		$this->request();
		$this->attachment('emp');
		$this->permission->method('isHr')->willReturn(false);

		$this->expectException(ForbiddenException::class);
		$this->service->delete('boss', 9);
	}

	public function testTheUploaderDeletesTheirFileOnAnOpenRequest(): void {
		$this->request(LeaveRequest::STATUS_PENDING);
		$attachment = $this->attachment('emp');
		$this->permission->method('isHr')->willReturn(false);
		$this->attachmentMapper->expects(self::once())->method('delete')->with($attachment);

		$this->service->delete('emp', 9);
	}

	public static function closedStatuses(): array {
		return [[LeaveRequest::STATUS_REJECTED], [LeaveRequest::STATUS_CANCELLED]];
	}

	#[DataProvider('closedStatuses')]
	public function testTheUploaderMayNotDeleteOnceTheRequestIsClosed(string $status): void {
		$this->request($status);
		$this->attachment('emp');
		$this->permission->method('isHr')->willReturn(false);
		$this->attachmentMapper->expects(self::never())->method('delete');

		$this->expectException(ForbiddenException::class);
		$this->service->delete('emp', 9);
	}

	public function testTheUploaderMayNotDeleteWhatTheyNoLongerSee(): void {
		// Uploaded while in the HR group, on someone else's request
		$this->request(LeaveRequest::STATUS_PENDING);
		$this->attachment('former-hr');
		$this->permission->method('isHr')->willReturn(false);
		$this->attachmentMapper->expects(self::never())->method('delete');

		$this->expectException(ForbiddenException::class);
		$this->service->delete('former-hr', 9);
	}

	public function testHrDeletesOnAClosedRequest(): void {
		$this->request(LeaveRequest::STATUS_REJECTED);
		$attachment = $this->attachment('emp');
		$this->permission->method('isHr')->willReturn(true);
		$this->attachmentMapper->expects(self::once())->method('delete')->with($attachment);

		$this->service->delete('hr', 9);
	}

	public function testAddingAndRemovingGoToTheLogsWithIdsOnly(): void {
		$this->request(LeaveRequest::STATUS_PENDING);
		$this->permission->method('isHr')->willReturn(false);
		$this->attachmentMapper->method('countForRequest')->willReturn(0);
		$this->attachmentMapper->method('insert')->willReturnCallback(static function (Attachment $a): Attachment {
			$a->setId(77);
			return $a;
		});
		$logged = [];
		$this->logger->method('info')->willReturnCallback(function (string $message, array $context) use (&$logged): void {
			$logged[] = $context;
		});
		$audited = [];
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(function (CriticalActionPerformedEvent $event) use (&$audited): void {
			$audited[] = vsprintf($event->getLogMessage(), $event->getParameters());
		});

		$attachment = $this->service->upload('emp', 5, $this->upload());
		$this->attachmentMapper->method('find')->willReturn($attachment);
		$this->service->delete('emp', 77);

		self::assertSame([
			['app' => 'absence', 'action' => 'attachment_added', 'actor' => 'emp', 'requestId' => 5, 'attachmentId' => 77],
			['app' => 'absence', 'action' => 'attachment_removed', 'actor' => 'emp', 'requestId' => 5, 'attachmentId' => 77],
		], $logged);
		self::assertSame([
			'Absence request 5: attachment 77 added by emp',
			'Absence request 5: attachment 77 removed by emp',
		], $audited);
	}
}
