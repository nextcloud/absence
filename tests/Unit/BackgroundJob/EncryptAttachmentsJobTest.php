<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\BackgroundJob;

use OCA\Absence\BackgroundJob\EncryptAttachmentsJob;
use OCA\Absence\Db\Attachment;
use OCA\Absence\Db\AttachmentMapper;
use OCA\Absence\Service\AttachmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EncryptAttachmentsJobTest extends TestCase {
	/** @var list<int> ids of the plaintext attachments, in id order */
	private array $plaintext = [];
	/** @var list<int> */
	private array $encrypted = [];

	private function runJob(int $failingId = 0): void {
		$attachments = $this->createMock(AttachmentMapper::class);
		$attachments->method('findUnencrypted')->willReturnCallback(function (int $afterId, int $limit): array {
			$ids = array_slice(array_values(array_filter($this->plaintext, static fn (int $id): bool => $id > $afterId)), 0, $limit);
			return array_map(static function (int $id): Attachment {
				$attachment = new Attachment();
				$attachment->setId($id);
				return $attachment;
			}, $ids);
		});
		$service = $this->createMock(AttachmentService::class);
		$service->method('encryptStored')->willReturnCallback(function (Attachment $attachment) use ($failingId): void {
			if ($attachment->getId() === $failingId) {
				throw new \RuntimeException('storage gone');
			}
			$this->encrypted[] = $attachment->getId();
			$this->plaintext = array_values(array_diff($this->plaintext, [$attachment->getId()]));
		});
		$job = new EncryptAttachmentsJob($this->createMock(ITimeFactory::class), $attachments, $service, $this->createMock(LoggerInterface::class));
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testAFailureDoesNotHoldTheOthersBackAndIsTriedAgain(): void {
		$this->plaintext = range(1, 250);

		$this->runJob(failingId: 2);

		self::assertSame([2], $this->plaintext);
		self::assertCount(249, $this->encrypted);

		$this->runJob();
		self::assertSame([], $this->plaintext);
	}

	public function testARunStopsAfterItsShare(): void {
		$this->plaintext = range(1, EncryptAttachmentsJob::PER_RUN + 5);

		$this->runJob();

		self::assertCount(5, $this->plaintext);
	}
}
