<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Notification;

use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\NotificationService;
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

		[$subject, $message] = match ($notification->getSubject()) {
			NotificationService::SUBJECT_NEW_REQUEST => [
				$l->t('New leave request from %s', [$employee]),
				$l->t('Review it in Absence.'),
			],
			NotificationService::SUBJECT_ESCALATION => [
				$l->t('Leave request from %s needs HR', [$employee]),
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
				$l->t('Reminder: %s is waiting for a decision', [$employee]),
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
			default => throw new UnknownNotificationException('Unknown subject'),
		};

		$notification->setParsedSubject($subject);
		if ($message !== '') {
			$notification->setParsedMessage($message);
		}
		$notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(ConfigService::APP_ID, 'app-dark.svg')));

		$link = $this->urlGenerator->linkToRouteAbsolute('absence.page.index') . '#/requests/' . $requestId;
		$notification->setLink($link);

		// Actionable approve/reject buttons for decision-makers.
		if (in_array($notification->getSubject(), [NotificationService::SUBJECT_NEW_REQUEST, NotificationService::SUBJECT_ESCALATION, NotificationService::SUBJECT_WITHDRAWAL], true)) {
			$open = $notification->createAction();
			$open->setLabel('open')
				->setParsedLabel($l->t('Review'))
				->setLink($link, 'WEB')
				->setPrimary(true);
			$notification->addParsedAction($open);
		}

		return $notification;
	}

	private function displayName(string $uid): string {
		if ($uid === '') {
			return '';
		}
		$user = $this->userManager->get($uid);
		return $user !== null ? $user->getDisplayName() : $uid;
	}
}
