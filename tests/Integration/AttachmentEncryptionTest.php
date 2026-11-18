<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\BackgroundJob\EncryptAttachmentsJob;
use OCA\Absence\Db\Attachment;
use OCA\Absence\Db\AttachmentMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\NotFoundException;
use OCA\Absence\Service\AttachmentService;
use OCA\Absence\Service\ConfigService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Security\ICrypto;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Attachments are encrypted at rest with the instance secret (§3.8), on a real
 * server: what is stored is not the document, it downloads as it was, and the
 * files stored before are encrypted by the background job.
 */
class AttachmentEncryptionTest extends TestCase {
	private const EMPLOYEE = 'itest-enc-emp';
	private const CONTENT = "%PDF-1.4\ninvented doctor's note\n";

	private AttachmentService $service;
	private AttachmentMapper $attachments;
	private LeaveRequest $request;
	private string $tmp = '';

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(AttachmentService::class);
		$this->attachments = Server::get(AttachmentMapper::class);
		$annual = 0;
		foreach (Server::get(LeaveTypeMapper::class)->findAll() as $type) {
			if ($type->getKey() === 'annual') {
				$annual = (int)$type->getId();
			}
		}
		$request = new LeaveRequest();
		$request->setEmployeeUid(self::EMPLOYEE);
		$request->setTypeId($annual);
		$request->setStartDate('2031-03-03');
		$request->setEndDate('2031-03-03');
		$request->setWorkingDays(1.0);
		$request->setStatus(LeaveRequest::STATUS_PENDING);
		$request->setCreatedAt(new \DateTime('2031-01-01 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2031-01-01 09:00:00'));
		$this->request = Server::get(LeaveRequestMapper::class)->insert($request);
		$this->tmp = (string)tempnam(sys_get_temp_dir(), 'att');
		file_put_contents($this->tmp, self::CONTENT);
	}

	protected function tearDown(): void {
		unlink($this->tmp);
		$this->service->purgeForRequests([$this->request->getId()]);
		Server::get(LeaveRequestMapper::class)->delete($this->request);
		parent::tearDown();
	}

	private function folder(): ISimpleFolder {
		return Server::get(IAppDataFactory::class)->get(ConfigService::APP_ID)->getFolder('attachments');
	}

	private function stored(string $name): ?string {
		try {
			return $this->folder()->getFile($name)->getContent();
		} catch (FilesNotFoundException) {
			return null;
		}
	}

	private function upload(): Attachment {
		return $this->service->upload(self::EMPLOYEE, $this->request->getId(), [
			'name' => 'note.pdf', 'type' => 'application/pdf', 'tmp_name' => $this->tmp, 'size' => strlen(self::CONTENT), 'error' => \UPLOAD_ERR_OK,
		]);
	}

	private function runJob(): void {
		$job = Server::get(EncryptAttachmentsJob::class);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testAnUploadIsStoredEncryptedAndDownloadsAsItWas(): void {
		$id = $this->upload()->getId();

		$stored = (string)$this->stored($id . '.enc');
		self::assertStringNotContainsString('doctor', $stored);
		self::assertSame(self::CONTENT, Server::get(ICrypto::class)->decrypt($stored));
		self::assertNull($this->stored((string)$id));
		self::assertSame(self::CONTENT, $this->service->download(self::EMPLOYEE, $id)['content']);
	}

	public function testTheJobEncryptsAFileStoredBeforeOnceAndItStillDownloads(): void {
		$attachment = new Attachment();
		$attachment->setRequestId($this->request->getId());
		$attachment->setUploaderUid(self::EMPLOYEE);
		$attachment->setName('note.pdf');
		$attachment->setSize(strlen(self::CONTENT));
		$attachment->setCreatedAt(new \DateTime('2031-01-01 09:00:00'));
		$id = $this->attachments->insert($attachment)->getId();
		$this->folder()->newFile((string)$id, self::CONTENT);
		self::assertSame(self::CONTENT, $this->service->download(self::EMPLOYEE, $id)['content']);

		$this->runJob();

		self::assertNull($this->stored((string)$id));
		$encrypted = (string)$this->stored($id . '.enc');
		self::assertStringNotContainsString('doctor', $encrypted);
		self::assertTrue($this->attachments->find($id)->getEncrypted());
		self::assertSame(self::CONTENT, $this->service->download(self::EMPLOYEE, $id)['content']);

		$this->runJob();
		self::assertSame($encrypted, $this->stored($id . '.enc'));
	}

	public function testAFileChangedOnDiskIsRefused(): void {
		$id = $this->upload()->getId();
		$stored = (string)$this->stored($id . '.enc');
		$this->folder()->getFile($id . '.enc')->putContent(($stored[0] === 'a' ? 'b' : 'a') . substr($stored, 1));

		$this->expectException(NotFoundException::class);
		$this->service->download(self::EMPLOYEE, $id);
	}
}
