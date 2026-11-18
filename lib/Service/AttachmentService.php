<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\Attachment;
use OCA\Absence\Db\AttachmentMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Exception\NotFoundException;
use OCA\Absence\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Files attached to a leave request (§3.8) — the doctor's note above all.
 *
 * The bytes live in the app's own appdata storage, never in anybody's Files:
 * a document in a user's home could be renamed, shared or deleted underneath
 * the record, and its visibility could not be governed by this app. Here the
 * API is the only door, and it opens for exactly two parties:
 *
 *  - **HR** always;
 *  - **the employee** on their own request — unless the request is of a
 *    confidential type (§5.7), which is withheld from them like everything
 *    else about it.
 *
 * The line manager deliberately has no access: they see the reason, but a
 * medical document is a step further than a sentence the employee chose to
 * write. Attachments never appear in the request history either — the
 * timeline is manager-visible, and "a file was added" already says too much.
 *
 * Server-side encryption never covers appdata, so the bytes are encrypted
 * here with {@see ICrypto}, keyed on the instance secret, and stored as
 * `<id>.enc`. Files stored before that are plaintext under `<id>` until
 * {@see \OCA\Absence\BackgroundJob\EncryptAttachmentsJob} encrypts them; the
 * row's `encrypted` flag says which of the two a row has.
 */
class AttachmentService {
	/** Uploads are documents, not media libraries. */
	public const MAX_SIZE_BYTES = 10 * 1024 * 1024;
	public const MAX_PER_REQUEST = 10;
	/** Stored bytes on all requests of one employee: the files sit outside any user quota. */
	public const MAX_PER_EMPLOYEE_BYTES = 200 * 1024 * 1024;
	private const ENCRYPTED_SUFFIX = '.enc';

	public function __construct(
		private AttachmentMapper $attachmentMapper,
		private LeaveRequestMapper $requestMapper,
		private PermissionService $permission,
		private IAppDataFactory $appDataFactory,
		private ClockService $clock,
		private IL10N $l,
		private LoggerInterface $logger,
		private IEventDispatcher $dispatcher,
		private ICrypto $crypto,
	) {
	}

	/**
	 * The attachments an actor may see on a request; [] when they may see none.
	 *
	 * @return Attachment[]
	 * @throws NotFoundException
	 */
	public function listForRequest(string $actorUid, int $requestId): array {
		$request = $this->loadVisibleRequest($actorUid, $requestId);
		if (!$this->canSee($actorUid, $request)) {
			return [];
		}
		return $this->attachmentMapper->findForRequest($requestId);
	}

	/**
	 * Whether the actor may add a file to this request — mirrored to the client
	 * as `canAttach` so the button only shows where the server would say yes.
	 */
	public function canAttach(string $actorUid, LeaveRequest $request): bool {
		if ($this->permission->isHr($actorUid)) {
			return true;
		}
		// The employee attaches to their own records — including HR-recorded sick
		// leave, which is exactly where a doctor's note belongs — but not to
		// confidential requests (they cannot see what is on them) and not to
		// closed ones (a rejected request needs no paperwork).
		return $actorUid === $request->getEmployeeUid()
			&& !$this->permission->isHrOnlyRequest($request)
			&& !in_array($request->getStatus(), LeaveRequest::TERMINAL_STATUSES, true);
	}

	/**
	 * @param array{name?:string,type?:string,tmp_name?:string,size?:int,error?:int} $upload a PHP upload array
	 * @throws ValidationException|ForbiddenException|NotFoundException
	 */
	public function upload(string $actorUid, int $requestId, #[\SensitiveParameter] array $upload): Attachment {
		$request = $this->loadVisibleRequest($actorUid, $requestId);
		if (!$this->canAttach($actorUid, $request)) {
			throw new ForbiddenException($this->l->t('Not allowed to attach files to this request'));
		}
		if (($upload['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)) {
			throw new ValidationException($this->l->t('The upload did not arrive intact. Please try again.'));
		}
		$size = (int)($upload['size'] ?? 0);
		if ($size <= 0 || $size > self::MAX_SIZE_BYTES) {
			throw new ValidationException($this->l->t('Attachments can be up to %s MB.', [(string)(self::MAX_SIZE_BYTES / 1024 / 1024)]));
		}
		if ($this->attachmentMapper->countForRequest($requestId) >= self::MAX_PER_REQUEST) {
			throw new ValidationException($this->l->t('This request already carries the maximum number of attachments.'));
		}
		if ($this->attachmentMapper->sumSizeForEmployee($request->getEmployeeUid()) + $size > self::MAX_PER_EMPLOYEE_BYTES) {
			throw new ValidationException($this->l->t('All attachments of one person together can be up to %s MB.', [(string)(self::MAX_PER_EMPLOYEE_BYTES / 1024 / 1024)]));
		}
		$name = $this->sanitizeName((string)($upload['name'] ?? ''));
		$content = file_get_contents($upload['tmp_name']);
		if ($content === false) {
			throw new ValidationException($this->l->t('The upload did not arrive intact. Please try again.'));
		}

		$attachment = new Attachment();
		$attachment->setRequestId($requestId);
		$attachment->setUploaderUid($actorUid);
		$attachment->setName($name);
		$attachment->setMime($this->sanitizeMime((string)($upload['type'] ?? '')));
		$attachment->setSize($size);
		$attachment->setCreatedAt($this->clock->now());
		$attachment->setEncrypted(true);
		$attachment = $this->attachmentMapper->insert($attachment);

		try {
			// Stored under the row id, not the client's filename: ids cannot
			// collide and cannot traverse anywhere. Only the ciphertext reaches
			// the storage calls, and so any trace of their exceptions.
			$this->storage()->newFile($this->storedName($attachment), $this->crypto->encrypt($content));
		} catch (\Throwable $e) {
			$this->attachmentMapper->delete($attachment);
			$this->logger->error('Absence: storing an attachment failed', ['exception' => $e]);
			throw new ValidationException($this->l->t('The file could not be stored. Please try again.'));
		}

		$this->logger->info('Absence action: attachment_added', [
			'app' => 'absence',
			'action' => 'attachment_added',
			'actor' => $actorUid,
			'requestId' => $requestId,
			'attachmentId' => $attachment->getId(),
		]);
		$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(
			'Absence request %s: attachment %s added by %s', [(string)$requestId, (string)$attachment->getId(), $actorUid],
		));
		return $attachment;
	}

	/**
	 * @return array{attachment:Attachment,content:string}
	 * @throws NotFoundException
	 */
	public function download(string $actorUid, int $attachmentId): array {
		$attachment = $this->loadAttachment($attachmentId);
		$this->loadVisibleAttachmentRequest($actorUid, $attachment);
		try {
			$stored = $this->storage()->getFile($this->storedName($attachment))->getContent();
		} catch (FilesNotFoundException) {
			throw new NotFoundException($this->l->t('Attachment not found'));
		}
		if (!$attachment->getEncrypted()) {
			return ['attachment' => $attachment, 'content' => $stored];
		}
		try {
			$content = $this->crypto->decrypt($stored);
		} catch (\Exception $e) {
			// Changed on disk, or the instance secret changed since it was stored
			$this->logger->error('Absence: a stored attachment could not be decrypted', [
				'app' => 'absence',
				'attachmentId' => $attachment->getId(),
				'exception' => $e,
			]);
			throw new NotFoundException($this->l->t('This attachment can no longer be read'));
		}
		return ['attachment' => $attachment, 'content' => $content];
	}

	/**
	 * Encrypts the plaintext file of an attachment stored before attachments
	 * were encrypted. The encrypted file is written next to it and read back
	 * before the plaintext one is deleted, and the row is switched last, so an
	 * interrupted run loses nothing and the next one finishes the job: a row
	 * whose plaintext file is gone already has its encrypted one.
	 */
	public function encryptStored(Attachment $attachment): void {
		$folder = $this->storage();
		$plainName = (string)$attachment->getId();
		$encryptedName = $plainName . self::ENCRYPTED_SUFFIX;
		try {
			$plaintext = $folder->getFile($plainName)->getContent();
		} catch (FilesNotFoundException) {
			$plaintext = null;
		}
		if ($plaintext !== null) {
			$folder->newFile($encryptedName, $this->crypto->encrypt($plaintext));
			if (!hash_equals($plaintext, $this->crypto->decrypt($folder->getFile($encryptedName)->getContent()))) {
				throw new \RuntimeException('The encrypted file does not read back the same');
			}
			$folder->getFile($plainName)->delete();
		}
		if (!$this->attachmentMapper->markEncrypted($attachment->getId())) {
			// Removed while it was being encrypted
			$this->removeStored($attachment);
		}
	}

	/**
	 * HR may remove anything; the uploader may remove their own file while they
	 * can still see the request and it is not closed. The attachment stays when
	 * its file could not be deleted.
	 *
	 * @throws NotFoundException|ForbiddenException
	 */
	public function delete(string $actorUid, int $attachmentId): void {
		$attachment = $this->loadAttachment($attachmentId);
		if (!$this->permission->isHr($actorUid)) {
			$request = $this->loadVisibleAttachmentRequest($actorUid, $attachment);
			if ($attachment->getUploaderUid() !== $actorUid
				|| in_array($request->getStatus(), LeaveRequest::TERMINAL_STATUSES, true)) {
				throw new ForbiddenException($this->l->t('Not allowed to remove this attachment'));
			}
		}
		$this->removeStored($attachment);
		$this->attachmentMapper->delete($attachment);
		$this->logger->info('Absence action: attachment_removed', [
			'app' => 'absence',
			'action' => 'attachment_removed',
			'actor' => $actorUid,
			'requestId' => $attachment->getRequestId(),
			'attachmentId' => $attachment->getId(),
		]);
		$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(
			'Absence request %s: attachment %s removed by %s', [(string)$attachment->getRequestId(), (string)$attachment->getId(), $actorUid],
		));
	}

	/**
	 * GDPR purge (§17): drop every attachment on the given requests, bytes and
	 * rows both.
	 *
	 * @param int[] $requestIds
	 * @throws \Throwable when a file could not be deleted; its row and those
	 *                    not reached yet are kept for the next try
	 */
	public function purgeForRequests(array $requestIds): void {
		foreach ($this->attachmentMapper->findForRequests($requestIds) as $attachment) {
			$this->removeStored($attachment);
			$this->attachmentMapper->delete($attachment);
		}
	}

	/**
	 * Delete the files of an attachment before its row, so that no file is left
	 * without one. Both names it may be stored under go: an attachment being
	 * encrypted has the two files for a moment. A file that is already gone counts
	 * as deleted.
	 *
	 * @throws \Throwable when a file is there and could not be deleted
	 */
	private function removeStored(Attachment $attachment): void {
		foreach ([(string)$attachment->getId(), $attachment->getId() . self::ENCRYPTED_SUFFIX] as $name) {
			try {
				$this->storage()->getFile($name)->delete();
			} catch (FilesNotFoundException) {
				continue;
			}
		}
	}

	private function storedName(Attachment $attachment): string {
		return $attachment->getId() . ($attachment->getEncrypted() ? self::ENCRYPTED_SUFFIX : '');
	}

	/**
	 * Who may see the files: HR, and the employee on their own non-confidential
	 * request. Deliberately narrower than canView() — the manager reads the
	 * request, not the medical documents on it.
	 */
	public function canSee(string $actorUid, LeaveRequest $request): bool {
		if ($this->permission->isHr($actorUid)) {
			return true;
		}
		return $actorUid === $request->getEmployeeUid()
			&& !$this->permission->isHrOnlyRequest($request);
	}

	private function loadRequest(int $requestId): LeaveRequest {
		try {
			return $this->requestMapper->find($requestId);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l->t('Request not found'));
		}
	}

	/**
	 * A request the actor may not see is refused like one that does not exist.
	 *
	 * @throws NotFoundException
	 */
	private function loadVisibleRequest(string $actorUid, int $requestId): LeaveRequest {
		$request = $this->loadRequest($requestId);
		if (!$this->permission->canView($actorUid, $request)) {
			throw new NotFoundException($this->l->t('Request not found'));
		}
		return $request;
	}

	/**
	 * The request an attachment is on, when the actor may see its files. An
	 * attachment the actor may not see is refused like one that does not exist.
	 *
	 * @throws NotFoundException
	 */
	private function loadVisibleAttachmentRequest(string $actorUid, Attachment $attachment): LeaveRequest {
		$request = $this->loadRequest($attachment->getRequestId());
		if (!$this->canSee($actorUid, $request)) {
			throw new NotFoundException($this->l->t('Attachment not found'));
		}
		return $request;
	}

	private function loadAttachment(int $attachmentId): Attachment {
		try {
			return $this->attachmentMapper->find($attachmentId);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l->t('Attachment not found'));
		}
	}

	/**
	 * One flat appdata folder; files are keyed by their globally-unique row id,
	 * so no per-request hierarchy is needed and no client input touches a path.
	 */
	private function storage(): ISimpleFolder {
		$appData = $this->appDataFactory->get(ConfigService::APP_ID);
		try {
			return $appData->getFolder('attachments');
		} catch (FilesNotFoundException) {
			return $appData->newFolder('attachments');
		}
	}

	private function sanitizeName(#[\SensitiveParameter] string $name): string {
		// The client name is display-only (bytes are stored by id), but keep it sane.
		$name = basename(str_replace('\\', '/', trim($name)));
		$name = preg_replace('/[\x00-\x1f]/', '', $name) ?? '';
		if ($name === '' || $name === '.' || $name === '..') {
			$name = 'attachment';
		}
		return mb_substr($name, 0, 255);
	}

	private function sanitizeMime(string $mime): string {
		return preg_match('#^[\w.+-]+/[\w.+-]+$#', $mime) === 1 ? mb_substr($mime, 0, 128) : 'application/octet-stream';
	}
}
