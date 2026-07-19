<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\LeaveRequest;
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

	public function __construct(
		private INotificationManager $notificationManager,
		private IMailer $mailer,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
		private LoggerInterface $logger,
	) {
	}

	public function notifyNewRequest(LeaveRequest $request, string $managerUid): void {
		$this->send($managerUid, self::SUBJECT_NEW_REQUEST, $request, true);
	}

	/** @param string[] $hrUids */
	public function notifyEscalation(LeaveRequest $request, array $hrUids): void {
		foreach ($hrUids as $uid) {
			$this->send($uid, self::SUBJECT_ESCALATION, $request, true);
		}
	}

	public function notifyDecision(LeaveRequest $request, bool $approved): void {
		$this->send($request->getEmployeeUid(), $approved ? self::SUBJECT_APPROVED : self::SUBJECT_REJECTED, $request, false);
	}

	public function notifyReminder(LeaveRequest $request, string $managerUid): void {
		$this->send($managerUid, self::SUBJECT_REMINDER, $request, true);
	}

	/** @param string[] $recipientUids */
	public function notifyWithdrawal(LeaveRequest $request, array $recipientUids): void {
		foreach ($recipientUids as $uid) {
			$this->send($uid, self::SUBJECT_WITHDRAWAL, $request, true);
		}
	}

	/** Tell the employee their withdrawal was declined — the leave stays approved. */
	public function notifyWithdrawalRejected(LeaveRequest $request): void {
		$this->send($request->getEmployeeUid(), self::SUBJECT_WITHDRAWAL_REJECTED, $request, false);
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

	private function send(string $recipientUid, string $subject, LeaveRequest $request, bool $actionable): void {
		$this->sendNotification($recipientUid, $subject, $request, $actionable);
		$this->sendEmail($recipientUid, $subject, $request);
	}

	private function sendNotification(string $recipientUid, string $subject, LeaveRequest $request, bool $actionable): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(ConfigService::APP_ID)
				->setUser($recipientUid)
				->setDateTime(new \DateTime())
				->setObject('absence_request', (string)$request->getId())
				->setSubject($subject, [
					'employee' => $request->getEmployeeUid(),
					'requestId' => (string)$request->getId(),
					'actionable' => $actionable,
				]);
			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning('Absence: notification failed', ['exception' => $e]);
		}
	}

	private function sendEmail(string $recipientUid, string $subject, LeaveRequest $request): void {
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
			[$heading, $body] = $this->emailContent($l, $subject, $request);

			$template = $this->mailer->createEMailTemplate('absence.' . $subject);
			$template->setSubject($heading);
			$template->addHeader();
			$template->addHeading($heading);
			$template->addBodyText($body);
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
	 * @return array{0:string,1:string} heading and body
	 */
	private function emailContent(\OCP\IL10N $l, string $subject, LeaveRequest $request): array {
		$employee = $this->displayName($request->getEmployeeUid());
		$range = $request->getStartDate() . ' – ' . $request->getEndDate();
		return match ($subject) {
			self::SUBJECT_NEW_REQUEST => [
				$l->t('New leave request from %s', [$employee]),
				$l->t('%1$s requested leave for %2$s. Please review it in Absence.', [$employee, $range]),
			],
			self::SUBJECT_ESCALATION => [
				$l->t('Leave request awaiting HR: %s', [$employee]),
				$l->t('A leave request from %1$s for %2$s has been escalated and needs an HR decision.', [$employee, $range]),
			],
			self::SUBJECT_APPROVED => [
				$l->t('Your leave was approved'),
				$l->t('Your leave request for %s has been approved. Enjoy!', [$range]),
			],
			self::SUBJECT_REJECTED => [
				$l->t('Your leave was declined'),
				$l->t('Your leave request for %1$s was declined. %2$s', [$range, (string)$request->getDecisionComment()]),
			],
			self::SUBJECT_REMINDER => [
				$l->t('Reminder: leave request from %s', [$employee]),
				$l->t('%1$s is still waiting for a decision on their leave for %2$s.', [$employee, $range]),
			],
			self::SUBJECT_WITHDRAWAL => [
				$l->t('Withdrawal requested: %s', [$employee]),
				$l->t('%1$s asked to withdraw approved leave for %2$s. Please review it in Absence.', [$employee, $range]),
			],
			self::SUBJECT_WITHDRAWAL_REJECTED => [
				$l->t('Your withdrawal request was declined'),
				// The refusal reason is recorded as a comment on the request, not in
				// decision_comment (that still holds the original approval note).
				$l->t('Your request to withdraw the leave for %s was declined — the leave stays approved. See the comments on the request for the reason.', [$range]),
			],
			self::SUBJECT_REPLACEMENT_ASSIGNED => [
				$l->t('You are covering for %s', [$employee]),
				$l->t('%1$s will be on leave for %2$s and has named you as their replacement.', [$employee, $range]),
			],
			self::SUBJECT_REPLACEMENT_CANCELLED => [
				$l->t('No longer covering for %s', [$employee]),
				$l->t('%1$s\'s leave for %2$s was cancelled — you no longer need to cover.', [$employee, $range]),
			],
			default => [$l->t('Absence update'), $l->t('There is an update on a leave request.')],
		};
	}

	private function displayName(string $uid): string {
		$user = $this->userManager->get($uid);
		return $user !== null ? $user->getDisplayName() : $uid;
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
