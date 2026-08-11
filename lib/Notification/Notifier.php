<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Notification;

use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\NoticeService;
use OCA\Absence\Service\NotificationService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private IUserManager $userManager,
	) {
	}

	#[\Override]
	public function getID(): string {
		return ConfigService::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10nFactory->get(ConfigService::APP_ID)->t('Absence');
	}

	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== ConfigService::APP_ID) {
			throw new UnknownNotificationException('Notification not from Absence');
		}
		$l = $this->l10nFactory->get(ConfigService::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$employee = $this->displayName((string)($params['employee'] ?? ''));
		$requestId = (string)($params['requestId'] ?? $notification->getObjectId());
		// Notifications stored before notes were carried have none of these keys.
		$note = trim((string)($params['note'] ?? ''));
		$noteAuthor = $this->displayName((string)($params['noteAuthor'] ?? ''));
		$notice = isset($params['noticeDays'], $params['noticePeriod'])
			? ['days' => (int)$params['noticeDays'], 'noticePeriod' => (int)$params['noticePeriod']]
			: null;

		[$subject, $message] = match ($notification->getSubject()) {
			NotificationService::SUBJECT_NEW_REQUEST => [
				$notice !== null
					? $l->t('Short notice: leave request from %s', [$employee])
					: $l->t('New leave request from %s', [$employee]),
				$l->t('Review it in Absence.'),
			],
			NotificationService::SUBJECT_ESCALATION => [
				$notice !== null
					? $l->t('Short notice: leave request from %s needs HR', [$employee])
					: $l->t('Leave request from %s needs HR', [$employee]),
				$l->t('This request was escalated and needs a decision.'),
			],
			NotificationService::SUBJECT_APPROVED => [
				$l->t('Your leave was approved 🎉'),
				$l->t('Enjoy your time off!'),
			],
			NotificationService::SUBJECT_REJECTED => [
				$l->t('Your leave request was declined'),
				'',
			],
			NotificationService::SUBJECT_REMINDER => [
				$notice !== null
					? $l->t('Short notice: %s is still waiting for a decision', [$employee])
					: $l->t('Reminder: %s is waiting for a decision', [$employee]),
				'',
			],
			NotificationService::SUBJECT_WITHDRAWAL => [
				$l->t('%s asked to withdraw approved leave', [$employee]),
				$l->t('Review the withdrawal in Absence.'),
			],
			NotificationService::SUBJECT_WITHDRAWAL_REJECTED => [
				$l->t('Your withdrawal request was declined'),
				$l->t('Your leave stays approved.'),
			],
			NotificationService::SUBJECT_REPLACEMENT_ASSIGNED => [
				$l->t('You are covering for %s 🌱', [$employee]),
				$l->t('They named you as their replacement while they are on leave.'),
			],
			NotificationService::SUBJECT_REPLACEMENT_CANCELLED => [
				$l->t('No longer covering for %s', [$employee]),
				$l->t('Their leave was cancelled.'),
			],
			NotificationService::SUBJECT_COMMENT => [
				$noteAuthor !== ''
					? $l->t('%s commented on a leave request', [$noteAuthor])
					: $l->t('New comment on a leave request'),
				'',
			],
			default => throw new UnknownNotificationException('Unknown subject'),
		};

		// The substance beats the boilerplate that would otherwise fill this line:
		// "Review it in Absence." says nothing the Review button doesn't, while how
		// short the notice is and what the employee wrote are the reasons to look at
		// all. The warning leads, because it bears on the answer rather than the ask.
		$detail = array_filter([
			$notice !== null ? NoticeService::sentence($l, $notice) : '',
			$note,
		]);
		if ($detail !== []) {
			$message = implode(' ', $detail);
		}

		$notification->setParsedSubject($subject);
		if ($message !== '') {
			$notification->setParsedMessage($message);
		}
		$notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(ConfigService::APP_ID, 'app-dark.svg')));

		$link = $this->urlGenerator->linkToRouteAbsolute('absence.page.index') . '#/requests/' . $requestId;
		$notification->setLink($link);

		$this->addDecisionActions($notification, $l, $requestId, $link);

		return $notification;
	}

	/**
	 * Buttons for the people who still owe this request a decision.
	 *
	 * Approving is the common answer and the one that needs nothing typed, so it
	 * happens in place: the button POSTs to the same endpoint the app uses, and the
	 * notification disappears. Declining is deliberately *not* a one-click verdict —
	 * §5.2 requires a reason, and a manager who could reject someone's holiday from
	 * a toast without saying why would be a worse app, not a faster one. Its button
	 * therefore opens the request with the reason box already unfolded, which is
	 * still a step better than "Review" for someone who has decided to say no.
	 */
	private function addDecisionActions(INotification $notification, IL10N $l, string $requestId, string $link): void {
		$subject = $notification->getSubject();
		$deciding = [
			NotificationService::SUBJECT_NEW_REQUEST,
			NotificationService::SUBJECT_ESCALATION,
			// The reminder exists *because* the decision is overdue — it is the place
			// a one-click answer pays off most.
			NotificationService::SUBJECT_REMINDER,
			NotificationService::SUBJECT_WITHDRAWAL,
		];
		if (!in_array($subject, $deciding, true)) {
			return;
		}

		// A withdrawal asks the opposite question, so the buttons have to read the
		// opposite way — the same wording the sidebar uses (RequestSidebar.vue).
		$isWithdrawal = $subject === NotificationService::SUBJECT_WITHDRAWAL;

		$approve = $notification->createAction();
		$approve->setLabel('approve')
			->setParsedLabel($isWithdrawal ? $l->t('Approve withdrawal') : $l->t('Approve'))
			->setLink($this->urlGenerator->linkToRouteAbsolute('absence.request.approve', ['id' => $requestId]), 'POST')
			->setPrimary(true);
		$notification->addParsedAction($approve);

		$decline = $notification->createAction();
		$decline->setLabel('decline')
			->setParsedLabel($isWithdrawal ? $l->t('Keep leave') : $l->t('Decline'))
			// The query rides inside the hash, where the SPA router reads it.
			->setLink($link . '?decide=decline', 'WEB')
			->setPrimary(false);
		$notification->addParsedAction($decline);

		$open = $notification->createAction();
		$open->setLabel('open')
			->setParsedLabel($l->t('Review'))
			->setLink($link, 'WEB')
			->setPrimary(false);
		$notification->addParsedAction($open);
	}

	private function displayName(string $uid): string {
		if ($uid === '') {
			return '';
		}
		$user = $this->userManager->get($uid);
		return $user !== null ? $user->getDisplayName() : $uid;
	}
}
