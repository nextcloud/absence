<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Listener;

use OCA\Absence\Service\EmployeeService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\User\Events\UserChangedEvent;
use Psr\Log\LoggerInterface;

/**
 * Records a change of someone's managers (§2.1). The server announces every
 * {@see \OCP\IUser::setManagerUids()} as the `managers` feature, with the uids
 * before and after; the actor is whoever is signed in, nobody for occ, a
 * background job or an account sync.
 *
 * @template-implements IEventListener<UserChangedEvent>
 */
class ManagerChangedListener implements IEventListener {
	public function __construct(
		private EmployeeService $employees,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof UserChangedEvent || $event->getFeature() !== 'managers') {
			return;
		}
		$after = $event->getValue();
		$before = $event->getOldValue();
		// The change is already stored: a failure here must not fail the request
		// that made it.
		try {
			$this->employees->recordManagerChange(
				$event->getUser()->getUID(),
				is_array($before) ? $before : [],
				is_array($after) ? $after : [],
				$this->userSession->getUser()?->getUID(),
			);
		} catch (\Throwable $e) {
			$this->logger->error('Absence: could not record a manager change', ['app' => 'absence', 'exception' => $e]);
		}
	}
}
