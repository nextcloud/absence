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
use OCA\Absence\Exception\NotFoundException;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\AttachmentService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Tests\Unit\ClockMockTrait;
use OCA\Absence\Tests\Unit\L10nMockTrait;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Attachments carry medical documents, so the permission matrix is the test
 * that matters: HR and the employee, never the manager; nothing at all on
 * confidential requests. The stored bytes are encrypted, and the files stored
 * before that are encrypted later without losing one.
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
	/** @var array<string, string> the stored files by name, once {@see storeInMemory()} is called */
	private array $files = [];

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
		// Stands in for the instance secret: what it seals carries no plaintext,
		// and anything it did not seal is refused.
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plaintext): string => 'sealed:' . base64_encode(strrev($plaintext)));
		$crypto->method('decrypt')->willReturnCallback(static function (string $sealed): string {
			$plaintext = str_starts_with($sealed, 'sealed:') ? base64_decode(substr($sealed, 7), true) : false;
			if ($plaintext === false) {
				throw new \RuntimeException('HMAC does not match.');
			}
			return strrev($plaintext);
		});
		$this->service = new AttachmentService(
			$this->attachmentMapper,
			$this->requestMapper,
			$this->permission,
			$factory,
			$this->clockAtRealTime(),
			$this->l10nMock(),
			$this->logger,
			$this->dispatcher,
			$crypto,
		);
	}

	/** Makes the storage keep files in {@see $files}. */
	private function storeInMemory(): void {
		$this->folder->method('newFile')->willReturnCallback(function (string $name, string $content): ISimpleFile {
			$this->files[$name] = $content;
			return $this->createMock(ISimpleFile::class);
		});
		$this->folder->method('getFile')->willReturnCallback(function (string $name): ISimpleFile {
			if (!isset($this->files[$name])) {
				throw new FilesNotFoundException();
			}
			$file = $this->createMock(ISimpleFile::class);
			$file->method('getContent')->willReturnCallback(fn (): string => $this->files[$name]);
			$file->method('delete')->willReturnCallback(function () use ($name): void {
				unset($this->files[$name]);
			});
			return $file;
		});
	}

	private function request(string $status = LeaveRequest::STATUS_APPROVED, bool $hrOnly = false): LeaveRequest {
		$request = new LeaveRequest();
		$request->setId(5);
		$request->setEmployeeUid('emp');
		$request->setTypeId(2);
		$request->setStatus($status);
		$this->requestMapper->method('find')->willReturn($request);
		$this->permission->method('canView')->willReturn(true);
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
		$this->folder->expects(self::once())->method('newFile')->with('77.enc', 'sealed:' . base64_encode('tser syas rotcod'));

		$attachment = $this->service->upload('emp', 5, $this->upload());

		self::assertSame('note.pdf', $attachment->getName());
		self::assertSame('emp', $attachment->getUploaderUid());
		self::assertTrue($attachment->getEncrypted());
	}

	public function testWhatIsUploadedIsStoredEncryptedAndDownloadsAsItWas(): void {
		$this->request();
		$this->permission->method('isHr')->willReturn(true);
		$this->storeInMemory();
		$this->attachmentMapper->method('insert')->willReturnCallback(static function (Attachment $a): Attachment {
			$a->setId(77);
			return $a;
		});

		$attachment = $this->service->upload('hr', 5, $this->upload());
		$this->attachmentMapper->method('find')->willReturn($attachment);

		self::assertSame(['77.enc'], array_keys($this->files));
		self::assertStringNotContainsString('doctor says rest', $this->files['77.enc']);
		// The limits count what was uploaded, not what is stored
		self::assertSame(16, $attachment->getSize());
		self::assertSame('doctor says rest', $this->service->download('hr', 77)['content']);
	}

	public function testAFileStoredBeforeEncryptionStillDownloads(): void {
		$this->request();
		$this->permission->method('isHr')->willReturn(true);
		$this->storeInMemory();
		$this->files['9'] = 'doctor says rest';
		$this->attachment('emp');

		self::assertSame('doctor says rest', $this->service->download('hr', 9)['content']);
	}

	public function testAFileChangedOnDiskIsRefusedAndLogged(): void {
		$this->request();
		$this->permission->method('isHr')->willReturn(true);
		$this->storeInMemory();
		$this->files['9.enc'] = 'doctor says rest, signed: Dr. Nobody';
		$this->attachment('emp')->setEncrypted(true);
		$this->logger->expects(self::once())->method('error')
			->with(self::anything(), self::callback(static fn (array $context): bool => $context['attachmentId'] === 9));

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('This attachment can no longer be read');
		$this->service->download('hr', 9);
	}

	public function testEncryptingAnOldFileReplacesItBeforeTheRowIsSwitched(): void {
		$this->storeInMemory();
		$this->files['9'] = 'doctor says rest';
		$filesWhenSwitched = null;
		$this->attachmentMapper->expects(self::once())->method('markEncrypted')->with(9)
			->willReturnCallback(function () use (&$filesWhenSwitched): bool {
				$filesWhenSwitched = array_keys($this->files);
				return true;
			});

		$this->service->encryptStored($this->attachment('emp'));

		self::assertSame(['9.enc'], $filesWhenSwitched);
		self::assertSame('sealed:' . base64_encode('tser syas rotcod'), $this->files['9.enc']);
	}

	public function testAnInterruptedEncryptionLosesNothingAndTheNextRunFinishesIt(): void {
		$this->storeInMemory();
		$this->files['9'] = 'doctor says rest';
		// Stopped after writing the encrypted file, before deleting the plaintext
		$this->files['9.enc'] = 'sealed:' . base64_encode('tser syas rotcod');
		$this->attachmentMapper->expects(self::exactly(2))->method('markEncrypted')->willReturn(true);

		$this->service->encryptStored($this->attachment('emp'));
		self::assertSame(['9.enc' => 'sealed:' . base64_encode('tser syas rotcod')], $this->files);

		// Stopped after deleting the plaintext, before switching the row
		$this->service->encryptStored($this->attachment('emp'));
		self::assertSame(['9.enc' => 'sealed:' . base64_encode('tser syas rotcod')], $this->files);
	}

	public function testAnEncryptedFileThatDoesNotReadBackKeepsThePlaintext(): void {
		$this->folder->method('newFile')->willReturn($this->createMock(ISimpleFile::class));
		$written = $this->createMock(ISimpleFile::class);
		$written->method('getContent')->willReturn('sealed:' . base64_encode('tser'));
		$plain = $this->createMock(ISimpleFile::class);
		$plain->method('getContent')->willReturn('doctor says rest');
		$plain->expects(self::never())->method('delete');
		$this->folder->method('getFile')->willReturnMap([['9', $plain], ['9.enc', $written]]);
		$this->attachmentMapper->expects(self::never())->method('markEncrypted');

		$this->expectException(\RuntimeException::class);
		$this->service->encryptStored($this->attachment('emp'));
	}

	public function testAnAttachmentRemovedWhileBeingEncryptedLeavesNoFile(): void {
		$this->storeInMemory();
		$this->files['9'] = 'doctor says rest';
		$this->attachmentMapper->method('markEncrypted')->willReturn(false);

		$this->service->encryptStored($this->attachment('emp'));

		self::assertSame([], $this->files);
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

	public function testAnUploadThatFillsTheCapOfTheEmployeeIsStored(): void {
		$this->request();
		$this->permission->method('isHr')->willReturn(true);
		$this->attachmentMapper->method('sumSizeForEmployee')->with('emp')
			->willReturn(AttachmentService::MAX_PER_EMPLOYEE_BYTES - 16);
		$this->attachmentMapper->method('insert')->willReturnArgument(0);
		$this->folder->expects(self::once())->method('newFile');

		self::assertSame(16, $this->service->upload('hr', 5, $this->upload())->getSize());
	}

	public function testAnUploadPastTheCapOfTheEmployeeIsRejectedWhoeverUploads(): void {
		$this->request();
		$this->permission->method('isHr')->willReturn(true);
		$this->attachmentMapper->method('sumSizeForEmployee')->with('emp')
			->willReturn(AttachmentService::MAX_PER_EMPLOYEE_BYTES - 15);
		$this->attachmentMapper->expects(self::never())->method('insert');
		$this->folder->expects(self::never())->method('newFile');

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('200 MB');
		$this->service->upload('hr', 5, $this->upload());
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

	public static function hiddenAttachmentCalls(): array {
		return [
			'download' => ['download'],
			'delete' => ['delete'],
		];
	}

	#[DataProvider('hiddenAttachmentCalls')]
	public function testAFileTheCallerMayNotSeeIsNotFoundLikeAnUnknownId(string $call): void {
		$this->request();
		$this->attachmentMapper->method('find')->willReturnCallback(function (int $id): Attachment {
			if ($id !== 9) {
				throw new DoesNotExistException('');
			}
			$attachment = new Attachment();
			$attachment->setId(9);
			$attachment->setRequestId(5);
			$attachment->setUploaderUid('emp');
			return $attachment;
		});
		$this->permission->method('isHr')->willReturn(false);
		$this->attachmentMapper->expects(self::never())->method('delete');

		$refusals = [];
		foreach ([9, 10] as $id) {
			try {
				$this->service->$call('boss', $id);
			} catch (NotFoundException $e) {
				$refusals[$id] = $e->getMessage();
			}
		}

		self::assertSame([9 => 'Attachment not found', 10 => 'Attachment not found'], $refusals);
	}

	public function testTheEmployeeMayNotDeleteAFileHrAdded(): void {
		$this->request();
		$this->attachment('hr');
		$this->permission->method('isHr')->willReturn(false);
		$this->attachmentMapper->expects(self::never())->method('delete');

		$this->expectException(ForbiddenException::class);
		$this->service->delete('emp', 9);
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

		$this->expectException(NotFoundException::class);
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
