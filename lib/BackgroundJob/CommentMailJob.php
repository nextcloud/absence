<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\BackgroundJob;

use OCA\Absence\Db\CommentMail;
use OCA\Absence\Db\CommentMailMapper;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\RequestComment;
use OCA\Absence\Db\RequestCommentMapper;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\NotificationService;
use OCA\Absence\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Mails the queued comments (§11): one mail per request and recipient with all
 * the comments they have not been mailed yet, once nobody has commented on the
 * request for QUIET_SECONDS.
 */
class CommentMailJob extends TimedJob {
	public const QUIET_SECONDS = 10 * 60;

	public function __construct(
		ITimeFactory $time,
		private ClockService $clock,
		private CommentMailMapper $commentMails,
		private RequestCommentMapper $commentMapper,
		private LeaveRequestMapper $requestMapper,
		private NotificationService $notifications,
		private PermissionService $permission,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
	}

	#[\Override]
	protected function run($argument): void {
		$quietSince = $this->clock->now()->getTimestamp() - self::QUIET_SECONDS;
		foreach ($this->commentMails->findRequestIds() as $requestId) {
			$comments = $this->commentMapper->findForRequest($requestId);
			$latest = end($comments);
			if ($latest !== false && $latest->getCreatedAt()->getTimestamp() > $quietSince) {
				continue;
			}
			$rows = $this->commentMails->findForRequest($requestId);
			// Gone before it is sent: a run that dies half way must not mail twice.
			$this->commentMails->deleteIds(array_map(static fn (CommentMail $row): int => $row->getId(), $rows));
			try {
				$request = $this->requestMapper->find($requestId);
			} catch (DoesNotExistException) {
				continue;
			}
			// Re-checked now: the request may have turned confidential (§5.7)
			// since the comments were queued.
			$hrOnly = $this->permission->isHrOnlyRequest($request);
			// Keyed by uid: PHP turns a numeric uid used as a key into an int.
			/** @var array<array-key, list<int>> $queued */
			$queued = [];
			foreach ($rows as $row) {
				$queued[$row->getRecipientUid()][] = $row->getCommentId();
			}
			foreach ($queued as $uid => $commentIds) {
				$uid = (string)$uid;
				$isHr = $this->permission->isHr($uid);
				if ($hrOnly && !$isHr) {
					continue;
				}
				$mailed = array_values(array_filter(
					$comments,
					static fn (RequestComment $comment): bool => in_array($comment->getId(), $commentIds, true)
						&& ($isHr || !$comment->getConfidential()),
				));
				$this->notifications->sendCommentMail($request, $uid, $mailed);
			}
		}
	}
}
