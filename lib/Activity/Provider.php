<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Activity;

use OCA\Absence\Service\ActivityPublisher;
use OCA\Absence\Service\ConfigService;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;

class Provider implements IProvider {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private IUserManager $userManager,
	) {
	}

	#[\Override]
	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== ConfigService::APP_ID) {
			throw new UnknownActivityException('Not an Absence event');
		}
		$l = $this->l10nFactory->get(ConfigService::APP_ID, $language);
		$params = $event->getSubjectParameters();
		$employee = $this->displayName((string)($params['employee'] ?? ''));
		$range = trim((string)($params['start'] ?? '') . ' – ' . (string)($params['end'] ?? ''), ' –');

		$subject = match ($event->getSubject()) {
			ActivityPublisher::SUBJECT_CREATED => $l->t('%1$s requested leave for %2$s', [$employee, $range]),
			ActivityPublisher::SUBJECT_APPROVED => $l->t('Leave for %1$s (%2$s) was approved', [$employee, $range]),
			ActivityPublisher::SUBJECT_REJECTED => $l->t('Leave for %1$s (%2$s) was declined', [$employee, $range]),
			ActivityPublisher::SUBJECT_CANCELLED => $l->t('Leave for %1$s (%2$s) was cancelled', [$employee, $range]),
			ActivityPublisher::SUBJECT_ESCALATED => $l->t('Leave for %1$s (%2$s) was escalated to HR', [$employee, $range]),
			ActivityPublisher::SUBJECT_WITHDRAWAL => $l->t('%1$s requested to withdraw leave for %2$s', [$employee, $range]),
			ActivityPublisher::SUBJECT_BALANCE_ADJUSTED => $this->balanceAdjusted($l, $employee, $params),
			default => throw new UnknownActivityException('Unknown subject'),
		};

		$event->setParsedSubject($subject);
		$event->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(ConfigService::APP_ID, 'app-dark.svg')));
		if ($event->getObjectId() > 0) {
			$event->setLink($this->urlGenerator->linkToRouteAbsolute('absence.page.index') . '#/requests/' . $event->getObjectId());
		}
		return $event;
	}

	/**
	 * "Leave balance of X was adjusted" told nobody anything: not by how much, not
	 * which figure, and not why — while HR is *required* to give a reason. The
	 * numbers are carried on the event, so say them.
	 *
	 * Older entries carry neither key and still have to render, so both are
	 * optional and the bare sentence remains the fallback.
	 *
	 * @param array<string,mixed> $params
	 */
	private function balanceAdjusted(\OCP\IL10N $l, string $employee, array $params): string {
		$summary = trim((string)($params['summary'] ?? ''));
		$note = trim((string)($params['note'] ?? ''));
		if ($summary === '') {
			return $l->t('Leave balance of %s was adjusted', [$employee]);
		}
		if ($note === '') {
			return $l->t('Leave balance of %1$s was adjusted: %2$s', [$employee, $summary]);
		}
		return $l->t('Leave balance of %1$s was adjusted: %2$s (%3$s)', [$employee, $summary, $note]);
	}

	private function displayName(string $uid): string {
		if ($uid === '') {
			return '';
		}
		$user = $this->userManager->get($uid);
		return $user !== null ? $user->getDisplayName() : $uid;
	}
}
