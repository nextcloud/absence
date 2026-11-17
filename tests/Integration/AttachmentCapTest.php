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
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\AttachmentService;
use OCA\Absence\Service\ConfigService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The cap on stored attachment bytes per employee (§3.8) on a real database.
 * The rows that fill the cap only state their size: no large file is written.
 */
class AttachmentCapTest extends TestCase {
	private const ALICE = 'itest-cap-alice';
	private const BOB = 'itest-cap-bob';

	private AttachmentService $service;
	private AttachmentMapper $attachments;
	private LeaveRequest $alices;
	private LeaveRequest $bobs;
	private string $tmp = '';

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(AttachmentService::class);
		$this->attachments = Server::get(AttachmentMapper::class);
		$this->alices = $this->request(self::ALICE);
		$this->bobs = $this->request(self::BOB);
		$this->tmp = (string)tempnam(sys_get_temp_dir(), 'att');
		file_put_contents($this->tmp, 'doctor says rest');
	}

	protected function tearDown(): void {
		unlink($this->tmp);
		foreach ($this->attachments->findForRequests([$this->alices->getId(), $this->bobs->getId()]) as $attachment) {
			try {
				($this->stored()[(string)$attachment->getId()] ?? null)?->delete();
			} finally {
				$this->attachments->delete($attachment);
			}
		}
		Server::get(LeaveRequestMapper::class)->delete($this->alices);
		Server::get(LeaveRequestMapper::class)->delete($this->bobs);
		parent::tearDown();
	}

	private function request(string $uid): LeaveRequest {
		$annual = 0;
		foreach (Server::get(LeaveTypeMapper::class)->findAll() as $type) {
			if ($type->getKey() === 'annual') {
				$annual = (int)$type->getId();
			}
		}
		$request = new LeaveRequest();
		$request->setEmployeeUid($uid);
		$request->setTypeId($annual);
		$request->setStartDate('2031-03-03');
		$request->setEndDate('2031-03-03');
		$request->setWorkingDays(1.0);
		$request->setStatus(LeaveRequest::STATUS_PENDING);
		$request->setCreatedAt(new \DateTime('2031-01-01 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2031-01-01 09:00:00'));
		return Server::get(LeaveRequestMapper::class)->insert($request);
	}

	/** A row that says it holds $size bytes, with no file behind it. */
	private function row(LeaveRequest $request, string $uploader, int $size): void {
		$attachment = new Attachment();
		$attachment->setRequestId($request->getId());
		$attachment->setUploaderUid($uploader);
		$attachment->setName('itest-note.pdf');
		$attachment->setSize($size);
		$attachment->setCreatedAt(new \DateTime('2031-01-01 09:00:00'));
		$this->attachments->insert($attachment);
	}

	/** @return array{name:string,type:string,tmp_name:string,size:int,error:int} a 16-byte upload */
	private function upload(): array {
		return ['name' => 'note.pdf', 'type' => 'application/pdf', 'tmp_name' => $this->tmp, 'size' => 16, 'error' => \UPLOAD_ERR_OK];
	}

	/** @return array<string, \OCP\Files\SimpleFS\ISimpleFile> the stored files by name */
	private function stored(): array {
		try {
			$files = Server::get(IAppDataFactory::class)->get(ConfigService::APP_ID)->getFolder('attachments')->getDirectoryListing();
		} catch (NotFoundException) {
			return [];
		}
		$byName = [];
		foreach ($files as $file) {
			$byName[$file->getName()] = $file;
		}
		return $byName;
	}

	public function testAnUploadMayFillTheCapAndTheNextIsRefused(): void {
		$this->row($this->alices, self::ALICE, AttachmentService::MAX_PER_EMPLOYEE_BYTES - 16);

		$attachment = $this->service->upload(self::ALICE, $this->alices->getId(), $this->upload());

		self::assertArrayHasKey((string)$attachment->getId(), $this->stored());
		$files = array_keys($this->stored());
		try {
			$this->service->upload(self::ALICE, $this->alices->getId(), $this->upload());
			self::fail('The upload past the cap was stored');
		} catch (ValidationException $e) {
			self::assertStringContainsString('200 MB', $e->getMessage());
		}
		self::assertSame(2, $this->attachments->countForRequest($this->alices->getId()));
		self::assertSame($files, array_keys($this->stored()));
	}

	public function testFilesCountForTheOwnerOfTheRequestWhoeverUploadedThem(): void {
		// Alice uploaded to a request of Bob: his cap is full, hers is untouched
		$this->row($this->bobs, self::ALICE, AttachmentService::MAX_PER_EMPLOYEE_BYTES);

		$this->service->upload(self::ALICE, $this->alices->getId(), $this->upload());

		self::assertSame(16, $this->attachments->sumSizeForEmployee(self::ALICE));
		self::assertSame(AttachmentService::MAX_PER_EMPLOYEE_BYTES, $this->attachments->sumSizeForEmployee(self::BOB));
		$this->expectException(ValidationException::class);
		$this->service->upload(self::BOB, $this->bobs->getId(), $this->upload());
	}
}
