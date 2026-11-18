<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\AttachmentMapper;
use OCA\Absence\Db\CommentMailMapper;
use OCA\Absence\Db\EmploymentMapper;
use OCA\Absence\Db\EntitlementEventMapper;
use OCA\Absence\Db\EntitlementMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\RequestCommentMapper;
use OCA\Absence\Db\RequestEventMapper;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\TTransactional;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/**
 * Deletes what the retention settings (§12) no longer keep:
 *
 *  - leave once a set number of full years have passed since its last day, with
 *    its comments, history, attachments, queued comment mails and notifications;
 *  - all the data of a former employee once a set number of full years have
 *    passed since their contract end date. Nobody without an end date is one.
 *
 * A setting of 0 keeps the records forever, and both are 0 by default.
 *
 * @psalm-type Counts = array{requests: int, attachments: int, entitlements: int, people: int}
 */
class RetentionService {
	use TTransactional;

	/** Requests, or former employees, deleted at once */
	public const BATCH_SIZE = 100;
	/** @var Counts */
	private const NONE = ['requests' => 0, 'attachments' => 0, 'entitlements' => 0, 'people' => 0];

	public function __construct(
		private ConfigService $config,
		private ClockService $clock,
		private LeaveRequestMapper $requestMapper,
		private RequestCommentMapper $commentMapper,
		private RequestEventMapper $eventMapper,
		private CommentMailMapper $commentMailMapper,
		private AttachmentMapper $attachmentMapper,
		private AttachmentService $attachments,
		private NotificationService $notifications,
		private EntitlementMapper $entitlementMapper,
		private EntitlementEventMapper $entitlementEventMapper,
		private EmploymentMapper $employmentMapper,
		private EmployeeService $employees,
		private IDBConnection $db,
		private IEventDispatcher $dispatcher,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Leave that ended before this day goes, null while leave is kept forever.
	 */
	public function leaveCutoff(): ?string {
		return $this->cutoff($this->config->getLeaveRetentionYears());
	}

	/**
	 * The data of people whose contract ended before this day goes, null while
	 * it is kept forever.
	 */
	public function formerEmployeeCutoff(): ?string {
		return $this->cutoff($this->config->getFormerEmployeeRetentionYears());
	}

	/**
	 * Deletes what the settings no longer keep, or with $dryRun only counts it.
	 * Without $maxBatches it runs until nothing is left; with it, it stops after
	 * that many batches of each rule, and the next run goes on.
	 *
	 * @return Counts what was deleted, or would be
	 */
	public function apply(bool $dryRun = false, ?int $maxBatches = null): array {
		$counts = self::NONE;
		// Former employees by uid: their old leave is counted with them, not again below
		$former = [];

		$formerCutoff = $this->formerEmployeeCutoff();
		if ($formerCutoff !== null) {
			$employments = fn (int $afterId): array => $this->employmentMapper->findEndedBefore($formerCutoff, $afterId, self::BATCH_SIZE);
			foreach ($this->batches($employments, $maxBatches) as $batch) {
				foreach ($batch as $employment) {
					$former[$employment->getEmployeeUid()] = true;
					$counts = self::sum($counts, $this->removeFormerEmployee($employment->getEmployeeUid(), $dryRun));
				}
			}
		}

		$leaveCutoff = $this->leaveCutoff();
		if ($leaveCutoff !== null) {
			$requests = fn (int $afterId): array => $this->requestMapper->findEndedBefore($leaveCutoff, $afterId, self::BATCH_SIZE);
			foreach ($this->batches($requests, $maxBatches) as $batch) {
				$batch = array_values(array_filter($batch, static fn (LeaveRequest $request): bool => !isset($former[$request->getEmployeeUid()])));
				$counts = self::sum($counts, $this->removeRequests($batch, $dryRun));
			}
		}

		if (!$dryRun && array_sum($counts) > 0) {
			$this->logger->info('Absence action: retention_applied', ['app' => ConfigService::APP_ID, 'action' => 'retention_applied'] + $counts);
			$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(
				'Absence retention deleted %s requests, %s attachments, %s entitlements and the data of %s former employees',
				[(string)$counts['requests'], (string)$counts['attachments'], (string)$counts['entitlements'], (string)$counts['people']],
			));
		}
		return $counts;
	}

	/**
	 * Today, $years years ago: 28 February for 29 February in a year without
	 * it. Null for 0.
	 */
	private function cutoff(int $years): ?string {
		if ($years === 0) {
			return null;
		}
		[$year, $month, $day] = array_map(intval(...), explode('-', $this->clock->serverToday()));
		$year -= $years;
		if (!checkdate($month, $day, $year)) {
			$day--;
		}
		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}

	/**
	 * Pages of rows by id, until a page is not full or $maxBatches pages were read.
	 *
	 * @template T of Entity
	 * @param \Closure(int): list<T> $page the rows after an id
	 * @return \Generator<int, non-empty-list<T>>
	 */
	private function batches(\Closure $page, ?int $maxBatches): \Generator {
		$afterId = 0;
		for ($read = 0; $maxBatches === null || $read < $maxBatches; $read++) {
			$rows = $page($afterId);
			if ($rows === []) {
				return;
			}
			yield $rows;
			if (count($rows) < self::BATCH_SIZE) {
				return;
			}
			$afterId = (int)$rows[count($rows) - 1]->getId();
		}
	}

	/**
	 * @param list<LeaveRequest> $requests
	 * @return Counts
	 */
	private function removeRequests(array $requests, bool $dryRun): array {
		if ($requests === []) {
			return self::NONE;
		}
		$ids = array_map(static fn (LeaveRequest $request): int => (int)$request->getId(), $requests);
		$counts = ['requests' => count($ids), 'attachments' => count($this->attachmentMapper->findForRequests($ids))] + self::NONE;
		if ($dryRun) {
			return $counts;
		}
		foreach ($requests as $request) {
			$this->notifications->dismiss($request);
		}
		// Files cannot be rolled back: they go first, outside the transaction
		$this->attachments->purgeForRequests($ids);
		$this->atomic(function () use ($ids): void {
			$this->commentMapper->deleteForRequests($ids);
			$this->eventMapper->deleteForRequests($ids);
			$this->commentMailMapper->deleteForRequests($ids);
			$this->requestMapper->deleteByIds($ids);
		}, $this->db);
		return $counts;
	}

	/**
	 * @return Counts
	 */
	private function removeFormerEmployee(string $uid, bool $dryRun): array {
		$counts = ['entitlements' => count($this->entitlementMapper->findForEmployee($uid)), 'people' => 1] + self::NONE;
		foreach (array_chunk($this->requestMapper->findAllForEmployee($uid), self::BATCH_SIZE) as $requests) {
			$counts = self::sum($counts, $this->removeRequests($requests, $dryRun));
		}
		if (!$dryRun) {
			$this->atomic(function () use ($uid): void {
				$this->entitlementMapper->deleteForEmployee($uid);
				$this->entitlementEventMapper->deleteForEmployee($uid);
				$this->employees->purge($uid);
			}, $this->db);
		}
		return $counts;
	}

	/**
	 * @param Counts $a
	 * @param Counts $b
	 * @return Counts
	 */
	private static function sum(array $a, array $b): array {
		foreach ($b as $kind => $count) {
			$a[$kind] += $count;
		}
		return $a;
	}
}
