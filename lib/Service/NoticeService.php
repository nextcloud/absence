<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\LeaveRequest;
use OCP\IL10N;

/**
 * The notice-period check (§8): did this request give the company the advance
 * warning it expects, and if not, how short was it?
 *
 * A companion to the coverage conflict warning — both tell a decider something
 * about a request that the dates alone do not, and neither blocks anything.
 *
 * Two deliberate limits on when a warning is produced:
 *
 *  - Only while the request is still awaiting a decision. Short notice is a fact
 *    about a decision somebody has to make now; on leave already approved,
 *    rejected or cancelled it is history, and repeating it there would leave a
 *    permanent reproach on the record.
 *  - Never for leave that has no approval workflow. Sick leave is recorded by HR
 *    after the fact and auto-approved types are booked straight through (§4.1),
 *    so nobody is being asked to weigh the notice given — and nobody can give
 *    notice of falling ill. Both reach APPROVED without ever being pending, so
 *    the status gate covers them without needing to know the type.
 */
class NoticeService {
	/** Statuses in which somebody still has to decide, so a warning is actionable. */
	private const PENDING_DECISION = [
		LeaveRequest::STATUS_PENDING,
		LeaveRequest::STATUS_ESCALATED,
	];

	public function __construct(
		private ConfigService $config,
		private ClockService $clock,
	) {
	}

	/**
	 * Calendar days from today to the first day of leave. Negative once the leave
	 * has started — an edit or a late HR correction can put it in the past.
	 *
	 * Calendar days, not working days: "two weeks' notice" is a fortnight on the
	 * wall calendar, and unlike the escalation window (which counts the days a
	 * manager actually had a chance to answer in) nothing here is about working time.
	 *
	 * The company's clock, not the viewer's: the notice period is one policy applied
	 * to one request, so it has to give the same answer for the manager, for HR and
	 * for the background job that mails them — a per-viewer boundary would let the
	 * sidebar and the email disagree by a day.
	 */
	public function daysUntilStart(LeaveRequest $request): int {
		$today = new \DateTimeImmutable($this->clock->serverToday());
		$start = new \DateTimeImmutable($request->getStartDate());
		return (int)$today->diff($start)->format('%r%a');
	}

	/**
	 * The short-notice warning for a request, or null when there is nothing to warn
	 * about: notice was sufficient, the admin switched the check off, or nobody is
	 * being asked to decide (see the class docblock).
	 *
	 * @return ?array{days:int,noticePeriod:int}
	 */
	public function warningFor(LeaveRequest $request): ?array {
		$noticePeriod = $this->config->getNoticePeriodDays();
		if ($noticePeriod <= 0 || !in_array($request->getStatus(), self::PENDING_DECISION, true)) {
			return null;
		}
		$days = $this->daysUntilStart($request);
		if ($days >= $noticePeriod) {
			return null;
		}
		return ['days' => $days, 'noticePeriod' => $noticePeriod];
	}

	/**
	 * The warning as a sentence, for whoever is about to read it.
	 *
	 * Static, and here rather than in the notifier or the mailer, because the same
	 * warning is shown in the notification, in the email and in the request sidebar:
	 * one string to translate and one wording to keep true, instead of three that
	 * drift. Takes the reader's IL10N because the same request is described to
	 * people in different languages.
	 *
	 * @param array{days:int,noticePeriod:int} $notice
	 */
	public static function sentence(IL10N $l, array $notice): string {
		$period = $notice['noticePeriod'];
		if ($notice['days'] > 0) {
			return $l->n(
				'The leave starts in %n day, less than the %s days of notice expected.',
				'The leave starts in %n days, less than the %s days of notice expected.',
				$notice['days'],
				[$period],
			);
		}
		if ($notice['days'] === 0) {
			return $l->t('The leave starts today, with none of the %s days of notice expected.', [$period]);
		}
		return $l->t('The leave has already started, though %s days of notice are expected.', [$period]);
	}
}
