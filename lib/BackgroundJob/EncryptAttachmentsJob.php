<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\BackgroundJob;

use OCA\Absence\Db\AttachmentMapper;
use OCA\Absence\Service\AttachmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Encrypts the attachments stored before attachments were encrypted (§3.8),
 * up to a number per run, in id order. An attachment that fails is skipped
 * and tried again on the next run; once all are encrypted a run is one query.
 */
class EncryptAttachmentsJob extends TimedJob {
	public const BATCH_SIZE = 100;
	public const PER_RUN = 1000;

	public function __construct(
		ITimeFactory $time,
		private AttachmentMapper $attachments,
		private AttachmentService $service,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
	}

	#[\Override]
	protected function run($argument): void {
		$after = 0;
		$done = 0;
		while ($done < self::PER_RUN) {
			$batch = $this->attachments->findUnencrypted($after, self::BATCH_SIZE);
			if ($batch === []) {
				return;
			}
			foreach ($batch as $attachment) {
				$after = $attachment->getId();
				$done++;
				try {
					$this->service->encryptStored($attachment);
				} catch (\Throwable $e) {
					$this->logger->warning('Absence: could not encrypt a stored attachment', [
						'app' => 'absence',
						'attachmentId' => $attachment->getId(),
						'exception' => $e,
					]);
				}
			}
		}
	}
}
