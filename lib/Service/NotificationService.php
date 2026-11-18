<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\CommentMailMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\RequestComment;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Sends Nextcloud notifications and emails for request lifecycle events (spec §11).
 * Every send is best-effort — a mail/notification failure never breaks the workflow.
 */
class NotificationService {
	// Notification subject keys, parsed by the Notifier.
	public const SUBJECT_NEW_REQUEST = 'new_request';
	public const SUBJECT_ESCALATION = 'escalation';
	public const SUBJECT_APPROVED = 'approved';
	public const SUBJECT_REJECTED = 'rejected';
	public const SUBJECT_REMINDER = 'reminder';
	public const SUBJECT_WITHDRAWAL = 'withdrawal';
	public const SUBJECT_WITHDRAWAL_REJECTED = 'withdrawal_rejected';
	public const SUBJECT_REPLACEMENT_ASSIGNED = 'replacement_assigned';
	public const SUBJECT_REPLACEMENT_CANCELLED = 'replacement_cancelled';
	public const SUBJECT_COMMENT = 'comment_added';
	public const SUBJECT_ABSENCE_RECORDED = 'absence_recorded';
	public const SUBJECT_MISSING_REGION = 'missing_region';
	public const SUBJECT_CARRYOVER_EXPIRING = 'carryover_expiring';
	public const SUBJECT_MANAGER_CHANGED = 'manager_changed';
	private const OBJECT_REGION = 'absence_region';
	private const OBJECT_CARRYOVER = 'absence_carryover';
	private const OBJECT_MANAGER = 'absence_manager';

	/**
	 * How much of a note survives into a Nextcloud notification. The free text is
	 * copied into every recipient's notification row and rendered on a single line
	 * there, so the notification carries a readable opening and the email — which
	 * has room for it — carries the whole thing.
	 */
	private const NOTE_PREVIEW_LENGTH = 200;

	public function __construct(
		private INotificationManager $notificationManager,
		private IMailer $mailer,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
		private NoticeService $notice,
		private LoggerInterface $logger,
		private CommentMailMapper $commentMails,
		private PermissionService $permission,
	) {
	}

	public function notifyNewRequest(LeaveRequest $request, string $managerUid): void {
		$this->send($managerUid, self::SUBJECT_NEW_REQUEST, $request, true, $request->getReason(), $request->getEmployeeUid(), $this->notice->warningFor($request));
	}

	/** @param string[] $hrUids */
	public function notifyEscalation(LeaveRequest $request, array $hrUids): void {
		$notice = $this->notice->warningFor($request);
		// Nobody decides their own request, so an HR member is not asked about theirs.
		$hrUids = array_diff($hrUids, [$request->getEmployeeUid()]);
		if ($hrUids === []) {
			$this->logger->warning('Absence: an escalated leave request has nobody in HR to decide it', [
				'app' => 'absence',
				'requestId' => $request->getId(),
			]);
		}
		foreach ($hrUids as $uid) {
			$this->send($uid, self::SUBJECT_ESCALATION, $request, true, $request->getReason(), $request->getEmployeeUid(), $notice);
		}
	}

	public function notifyDecision(LeaveRequest $request, bool $approved): void {
		// The decision comment is the whole substance of the message for a rejection
		// and the manager's note on an approval — read it off the request the caller
		// just wrote rather than making every call site pass it again.
		$this->send(
			$request->getEmployeeUid(),
			$approved ? self::SUBJECT_APPROVED : self::SUBJECT_REJECTED,
			$request,
			false,
			$request->getDecisionComment(),
			$request->getDecidedBy(),
		);
	}

	public function notifyReminder(LeaveRequest $request, string $managerUid): void {
		// Warn again, and by now more sharply: the reminder fires days after the
		// request arrived, so whatever notice it gave has shrunk since.
		$this->send($managerUid, self::SUBJECT_REMINDER, $request, true, $request->getReason(), $request->getEmployeeUid(), $this->notice->warningFor($request));
	}

	/** @param string[] $recipientUids */
	public function notifyWithdrawal(LeaveRequest $request, array $recipientUids): void {
		foreach ($recipientUids as $uid) {
			$this->send($uid, self::SUBJECT_WITHDRAWAL, $request, true);
		}
	}

	/**
	 * Tell the employee their withdrawal was declined. The leave stays approved.
	 * The reason lives in a comment rather than on the request (see
	 * {@see RequestService::reject()}), so the caller has to hand it over.
	 */
	public function notifyWithdrawalRejected(LeaveRequest $request, ?string $comment = null, ?string $actorUid = null): void {
		$this->send($request->getEmployeeUid(), self::SUBJECT_WITHDRAWAL_REJECTED, $request, false, $comment, $actorUid);
	}

	/**
	 * Tell the other people on a request that someone commented on it. Without
	 * this a comment only exists behind the request's Comments tab, which nobody
	 * opens unless they already know there is something to read.
	 *
	 * The notification goes out right away. The e-mail waits in a queue, so that
	 * a back-and-forth arrives as one mail per recipient once the thread is quiet
	 * ({@see \OCA\Absence\BackgroundJob\CommentMailJob}).
	 *
	 * @param string[] $recipientUids
	 */
	public function notifyComment(LeaveRequest $request, RequestComment $comment, array $recipientUids): void {
		$authorUid = $comment->getAuthorUid();
		$queued = [];
		foreach (array_unique(array_filter($recipientUids)) as $uid) {
			// A disabled account cannot act on any of this.
			if ($uid === $authorUid || $this->userManager->get($uid)?->isEnabled() === false) {
				continue;
			}
			$this->sendNotification($uid, self::SUBJECT_COMMENT, $request, false, trim($comment->getBody()), $authorUid, null);
			$queued[] = $uid;
		}
		try {
			$this->commentMails->queue($comment, $queued);
		} catch (\Throwable $e) {
			$this->logger->warning('Absence: could not queue the comment email', ['exception' => $e]);
		}
	}

	/**
	 * Mail one recipient the comments of a request they have not been mailed yet.
	 *
	 * @param list<RequestComment> $comments oldest first
	 */
	public function sendCommentMail(LeaveRequest $request, string $recipientUid, array $comments): void {
		if ($comments === [] || $this->userManager->get($recipientUid)?->isEnabled() === false) {
			return;
		}
		$notes = array_map(static fn (RequestComment $comment): array => [
			'author' => $comment->getAuthorUid(),
			'text' => trim($comment->getBody()),
		], $comments);
		$this->sendEmail($recipientUid, self::SUBJECT_COMMENT, $request, $notes, null);
	}

	/** Tell the nominated replacement they now cover for the employee (§5.1). */
	public function notifyReplacementAssigned(LeaveRequest $request): void {
		$uid = $request->getReplacementUid();
		if ($uid !== null && $uid !== '') {
			$this->send($uid, self::SUBJECT_REPLACEMENT_ASSIGNED, $request, false);
		}
	}

	/** Tell the replacement the leave was cancelled and they no longer need to cover. */
	public function notifyReplacementCancelled(LeaveRequest $request): void {
		$uid = $request->getReplacementUid();
		if ($uid !== null && $uid !== '') {
			$this->send($uid, self::SUBJECT_REPLACEMENT_CANCELLED, $request, false);
		}
	}

	/**
	 * Tell the line manager that HR recorded an absence for one of their reports
	 * (§5.6). Who and when only, for every leave type: that is what the team
	 * timeline shows them, and saying more for some types would give away which
	 * ones are confidential (§5.7).
	 */
	public function notifyAbsenceRecorded(LeaveRequest $request, string $managerUid): void {
		$this->send($managerUid, self::SUBJECT_ABSENCE_RECORDED, $request, false);
	}

	/**
	 * @param ?string $note free text written by a person that the recipient would
	 *                      otherwise only find by opening the request: the
	 *                      applicant's reason, a decision comment, or a comment
	 *                      left on the request
	 * @param ?string $noteAuthorUid who wrote $note, so it can be attributed
	 * @param ?array{days:int,noticePeriod:int} $notice from {@see NoticeService::warningFor()}
	 *
	 * Only the messages that ask somebody to decide carry a $notice, and they carry
	 * the numbers rather than a finished sentence: the notification is phrased later,
	 * in whatever language the person who opens it reads.
	 *
	 * On a confidential request (§5.7) only HR gets $note and its author, as in the app.
	 */
	private function send(string $recipientUid, string $subject, LeaveRequest $request, bool $actionable, ?string $note = null, ?string $noteAuthorUid = null, ?array $notice = null): void {
		// A disabled account cannot act on any of this.
		if ($this->userManager->get($recipientUid)?->isEnabled() === false) {
			return;
		}
		if ($this->permission->isHrOnlyRequest($request) && !$this->permission->isHr($recipientUid)) {
			$note = null;
			$noteAuthorUid = null;
		}
		$note = trim((string)$note);
		$this->sendNotification($recipientUid, $subject, $request, $actionable, $note, $noteAuthorUid, $notice);
		$this->sendEmail($recipientUid, $subject, $request, $note !== '' ? [['author' => $noteAuthorUid, 'text' => $note]] : [], $notice);
	}

	/** @param ?array{days:int,noticePeriod:int} $notice */
	private function sendNotification(string $recipientUid, string $subject, LeaveRequest $request, bool $actionable, string $note, ?string $noteAuthorUid, ?array $notice): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(ConfigService::APP_ID)
				->setUser($recipientUid)
				->setDateTime(new \DateTime())
				->setObject('absence_request', (string)$request->getId())
				->setSubject($subject, [
					'employee' => $request->getEmployeeUid(),
					'requestId' => (string)$request->getId(),
					'start' => $request->getStartDate(),
					'end' => $request->getEndDate(),
					'actionable' => $actionable,
					'note' => $this->preview($note),
					'noteAuthor' => (string)$noteAuthorUid,
					'noticeDays' => $notice['days'] ?? null,
					'noticePeriod' => $notice['noticePeriod'] ?? null,
					// What the Approve button of this notification answers, see Notifier.
					'updatedAt' => $request->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
				]);
			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning('Absence: notification failed', ['exception' => $e]);
		}
	}

	/**
	 * Squeeze a note onto the single line a notification gives it: collapse the
	 * line breaks it may contain and cut it to length. The email carries the rest.
	 */
	private function preview(string $note): string {
		$note = trim((string)preg_replace('/\s+/u', ' ', $note));
		if (mb_strlen($note) <= self::NOTE_PREVIEW_LENGTH) {
			return $note;
		}
		return mb_substr($note, 0, self::NOTE_PREVIEW_LENGTH - 1) . '…';
	}

	/**
	 * @param list<array{author:?string,text:string}> $notes what people wrote, quoted in full
	 * @param ?array{days:int,noticePeriod:int} $notice
	 */
	private function sendEmail(string $recipientUid, string $subject, LeaveRequest $request, array $notes, ?array $notice): void {
		$user = $this->userManager->get($recipientUid);
		if (!$user instanceof IUser) {
			return;
		}
		$email = $user->getEMailAddress();
		if (!$email) {
			return;
		}
		try {
			$lang = $this->l10nFactory->getUserLanguage($user);
			$l = $this->l10nFactory->get(ConfigService::APP_ID, $lang);
			[$heading, $body] = $this->emailContent($l, $subject, $request, $notes, $notice !== null);

			$template = $this->mailer->createEMailTemplate('absence.' . $subject);
			$template->setSubject($heading);
			$template->addHeader();
			$template->addHeading($heading);
			$template->addBodyText($body);
			// Ahead of the note, because it bears on whether to say yes at all rather
			// than on what the employee wants.
			if ($notice !== null) {
				$template->addBodyText(NoticeService::sentence($l, $notice));
			}
			// Quote the notes verbatim under the summary, attributed. addBodyListItem
			// escapes the text and keeps its line breaks, so a comment written as
			// several lines still reads as several lines.
			foreach ($notes as $note) {
				$template->addBodyListItem(
					$note['text'],
					$note['author'] !== null && $note['author'] !== ''
						? $l->t('%s wrote:', [$this->displayName($note['author'])])
						: $l->t('Comment:'),
				);
			}
			$template->addBodyButton(
				$l->t('Open Absence'),
				$this->urlGenerator->linkToRouteAbsolute('absence.page.index') . '#/requests/' . $request->getId(),
			);
			$template->addFooter();

			$message = $this->mailer->createMessage();
			$message->setTo([$email => $user->getDisplayName()]);
			$message->useTemplate($template);
			$this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger->warning('Absence: email failed', ['exception' => $e]);
		}
	}

	/**
	 * @param list<array{author:?string,text:string}> $notes
	 * @return array{0:string,1:string} heading and body
	 */
	private function emailContent(\OCP\IL10N $l, string $subject, LeaveRequest $request, array $notes, bool $shortNotice): array {
		$employee = $this->displayName($request->getEmployeeUid());
		$noteAuthorUid = $notes[0]['author'] ?? null;
		$author = $noteAuthorUid !== null && $noteAuthorUid !== '' ? $this->displayName($noteAuthorUid) : '';
		$range = $request->getStartDate() . ' – ' . $request->getEndDate();
		// The heading is also the mail's subject line, so a short-notice request says
		// so in the inbox — where the decider still has time to act on it.
		return match ($subject) {
			self::SUBJECT_NEW_REQUEST => [
				$shortNotice
					? $l->t('Short notice: leave request from %s', [$employee])
					: $l->t('New leave request from %s', [$employee]),
				$l->t('%1$s requested leave for %2$s. Please review it in Absence.', [$employee, $range]),
			],
			self::SUBJECT_ESCALATION => [
				$shortNotice
					? $l->t('Short notice: leave request awaiting HR: %s', [$employee])
					: $l->t('Leave request awaiting HR: %s', [$employee]),
				$l->t('A leave request from %1$s for %2$s has been escalated and needs an HR decision.', [$employee, $range]),
			],
			self::SUBJECT_APPROVED => [
				$l->t('Your leave was approved'),
				$l->t('Your leave request for %s has been approved. Enjoy!', [$range]),
			],
			self::SUBJECT_REJECTED => [
				$l->t('Your leave was declined'),
				// The reason follows as the quoted note; it is required on a rejection.
				$l->t('Your leave request for %s was declined.', [$range]),
			],
			self::SUBJECT_REMINDER => [
				$shortNotice
					? $l->t('Short notice: %s is still waiting for a decision', [$employee])
					: $l->t('Reminder: leave request from %s', [$employee]),
				$l->t('%1$s is still waiting for a decision on their leave for %2$s.', [$employee, $range]),
			],
			self::SUBJECT_WITHDRAWAL => [
				$l->t('Withdrawal requested: %s', [$employee]),
				$l->t('%1$s asked to withdraw approved leave for %2$s. Please review it in Absence.', [$employee, $range]),
			],
			self::SUBJECT_WITHDRAWAL_REJECTED => [
				$l->t('Your withdrawal request was declined'),
				// The refusal reason is recorded as a comment on the request, not in
				// decision_comment (that still holds the original approval note) — it
				// is passed in as the note and quoted below, so there is no need to
				// send the employee looking for it.
				$l->t('Your request to withdraw the leave for %s was declined. The leave stays approved.', [$range]),
			],
			self::SUBJECT_REPLACEMENT_ASSIGNED => [
				$l->t('You are covering for %s', [$employee]),
				$l->t('%1$s will be on leave for %2$s and has named you as their replacement.', [$employee, $range]),
			],
			self::SUBJECT_REPLACEMENT_CANCELLED => [
				$l->t('No longer covering for %s', [$employee]),
				$l->t('%1$s\'s leave for %2$s was cancelled. You no longer need to cover.', [$employee, $range]),
			],
			self::SUBJECT_ABSENCE_RECORDED => [
				$l->t('%s is absent', [$employee]),
				$l->t('HR recorded an absence for %1$s for %2$s.', [$employee, $range]),
			],
			self::SUBJECT_COMMENT => count($notes) > 1 ? [
				$l->t('New comments on the leave request of %s', [$employee]),
				$l->n('There is %n new comment on the leave request of %1$s for %2$s.', 'There are %n new comments on the leave request of %1$s for %2$s.', count($notes), [$employee, $range]),
			] : [
				$author !== '' ? $l->t('New comment from %s', [$author]) : $l->t('New comment on a leave request'),
				$l->t('There is a new comment on the leave request of %1$s for %2$s.', [$employee, $range]),
			],
			default => [$l->t('Absence update'), $l->t('There is an update on a leave request.')],
		};
	}

	private function displayName(string $uid): string {
		$user = $this->userManager->get($uid);
		return $user !== null ? $user->getDisplayName() : $uid;
	}

	/**
	 * Asks someone to set their holiday region code (§7.2), on HR's behalf, or
	 * to complete it when it only names their country ($code, with $example
	 * one of its regions).
	 */
	public function notifyMissingRegion(string $uid, string $code = '', ?string $example = null): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(ConfigService::APP_ID)
				->setUser($uid)
				->setDateTime(new \DateTime())
				->setObject(self::OBJECT_REGION, $uid)
				->setSubject(self::SUBJECT_MISSING_REGION, ['code' => $code, 'example' => (string)$example]);
			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning('Absence: notification failed', ['exception' => $e]);
		}
	}

	/**
	 * Removes the region code reminder once the person has set one.
	 */
	public function dismissMissingRegion(string $uid): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(ConfigService::APP_ID)
				->setUser($uid)
				->setObject(self::OBJECT_REGION, $uid);
			$this->notificationManager->markProcessed($notification);
		} catch (\Throwable $e) {
			$this->logger->debug('Absence: could not dismiss notifications', ['exception' => $e]);
		}
	}

	/**
	 * Tells an employee how many of their carried-over days are about to expire
	 * (§6.2), by notification and by email.
	 *
	 * @param string $expiryDate 'YYYY-MM-DD'
	 */
	public function notifyCarryOverExpiring(string $uid, float $days, string $expiryDate): void {
		$user = $this->userManager->get($uid);
		// A disabled account cannot act on any of this.
		if ($user?->isEnabled() === false) {
			return;
		}
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(ConfigService::APP_ID)
				->setUser($uid)
				->setDateTime(new \DateTime())
				->setObject(self::OBJECT_CARRYOVER, $expiryDate)
				->setSubject(self::SUBJECT_CARRYOVER_EXPIRING, ['days' => $days, 'date' => $expiryDate]);
			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning('Absence: notification failed', ['exception' => $e]);
		}
		$email = $user->getEMailAddress();
		if (!$user instanceof IUser || !$email) {
			return;
		}
		try {
			$l = $this->l10nFactory->get(ConfigService::APP_ID, $this->l10nFactory->getUserLanguage($user));
			$heading = $l->t('Carried-over days are about to expire');
			$template = $this->mailer->createEMailTemplate('absence.' . self::SUBJECT_CARRYOVER_EXPIRING);
			$template->setSubject($heading);
			$template->addHeader();
			$template->addHeading($heading);
			$template->addBodyText(CarryOverReminderService::sentence($l, $days, $expiryDate) . ' ' . CarryOverReminderService::hint($l));
			$template->addBodyButton(
				$l->t('Open Absence'),
				$this->urlGenerator->linkToRouteAbsolute('absence.page.index') . '#/my',
			);
			$template->addFooter();

			$message = $this->mailer->createMessage();
			$message->setTo([$email => $user->getDisplayName()]);
			$message->useTemplate($template);
			$this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger->warning('Absence: email failed', ['exception' => $e]);
		}
	}

	/**
	 * Tells an employee and HR that the employee's manager changed (§2.1), and
	 * who changed it. The notification carries uids only; the names are read
	 * when it is shown. Nobody is told about their own change.
	 *
	 * @param list<string> $before
	 * @param list<string> $after
	 * @param string[] $hrUids
	 */
	public function notifyManagerChanged(string $employeeUid, array $before, array $after, string $actorUid, array $hrUids): void {
		foreach (array_unique([$employeeUid, ...$hrUids]) as $uid) {
			if ($uid === $actorUid || $this->userManager->get($uid)?->isEnabled() !== true) {
				continue;
			}
			try {
				$notification = $this->notificationManager->createNotification();
				$notification->setApp(ConfigService::APP_ID)
					->setUser($uid)
					->setDateTime(new \DateTime())
					->setObject(self::OBJECT_MANAGER, $employeeUid)
					->setSubject(self::SUBJECT_MANAGER_CHANGED, ['employee' => $employeeUid, 'actor' => $actorUid, 'before' => $before, 'after' => $after]);
				$this->notificationManager->notify($notification);
			} catch (\Throwable $e) {
				$this->logger->warning('Absence: notification failed', ['exception' => $e]);
			}
		}
	}

	public function dismiss(LeaveRequest $request): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(ConfigService::APP_ID)
				->setObject('absence_request', (string)$request->getId());
			$this->notificationManager->markProcessed($notification);
		} catch (\Throwable $e) {
			$this->logger->debug('Absence: could not dismiss notifications', ['exception' => $e]);
		}
	}
}
