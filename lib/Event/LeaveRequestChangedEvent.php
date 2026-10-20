<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Event;

use OCA\Absence\Api\Absence;
use OCP\EventDispatcher\Event;

/**
 * A leave request was created, changed or decided. Dispatched once the change
 * is committed, never for a change that was rolled back.
 *
 * Listen with IRegistrationContext::registerEventListener(). The action is the
 * history key (§3.7): request_created, request_updated, request_edited_superseding,
 * request_hr_edited, withdrawal_requested, withdrawal_approved, withdrawal_rejected,
 * request_cancelled, request_approved, request_rejected, request_escalated. New keys
 * may appear, so react to the absence rather than to a closed list of actions.
 *
 * Comments do not change the absence and are not announced. When a user is deleted
 * their requests are removed without an event: listen to core's UserDeletedEvent.
 *
 * @since 1.1.0
 */
class LeaveRequestChangedEvent extends Event {
	/**
	 * @param string $action the history key (§3.7), e.g. request_created, request_approved, request_cancelled
	 * @param Absence $absence the request after the change; its status is `closed` once rejected or cancelled
	 * @param Absence|null $previous the request before an edit, to free its old dates; null for other actions
	 */
	public function __construct(
		private string $action,
		private Absence $absence,
		private ?Absence $previous = null,
	) {
		parent::__construct();
	}

	/** @since 1.1.0 */
	public function getAction(): string {
		return $this->action;
	}

	/** @since 1.1.0 */
	public function getAbsence(): Absence {
		return $this->absence;
	}

	/**
	 * Dates and type before an edit (request_updated, request_hr_edited), null otherwise.
	 *
	 * @since 1.1.0
	 */
	public function getPreviousAbsence(): ?Absence {
		return $this->previous;
	}
}
