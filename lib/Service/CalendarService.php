<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Sabre\VObject\Component\VCalendar;

/**
 * Leave in force in Nextcloud Calendar (spec §10). Nothing is stored: the DAV
 * calendars build their events from the requests on every read, the personal
 * calendar ({@see \OCA\Absence\DAV\PersonalCalendar}) with
 * {@see personalEvents()} and the team calendar each employee and HR member gets
 * in their own calendar home ({@see \OCA\Absence\DAV\TeamCalendar}) with
 * {@see teamEvents()}.
 */
class CalendarService {
	public function __construct(
		private ConfigService $config,
		private LeaveRequestMapper $requestMapper,
		private LeaveTypeMapper $leaveTypeMapper,
		private IUserManager $userManager,
		private IFactory $l10nFactory,
		private PermissionService $permission,
		private EmployeeDirectory $employees,
		private ClockService $clock,
	) {
	}

	/**
	 * Whether the employee gets a personal Absence calendar: while it is switched
	 * on and they have leave in force.
	 */
	public function hasPersonalCalendar(string $uid): bool {
		return $this->config->isCalDavPersonalEnabled()
			&& $this->requestMapper->findInForceForEmployee($uid, 1) !== [];
	}

	/**
	 * Display name of the personal calendar, in the employee's language.
	 */
	public function personalCalendarName(string $uid): string {
		return $this->personalL10n($uid)->t('Absence');
	}

	/**
	 * The events of the employee's personal calendar, one all-day event per
	 * leave in force, by object name.
	 *
	 * @return array<string, array{ics: string, lastModified: int|null}>
	 */
	public function personalEvents(string $uid): array {
		if (!$this->config->isCalDavPersonalEnabled()) {
			return [];
		}
		$events = [];
		foreach ($this->requestMapper->findInForceForEmployee($uid) as $request) {
			$eventUid = 'absence-' . $request->getId();
			$events[$eventUid . '.ics'] = [
				'ics' => $this->buildIcs($request, $eventUid, $this->personalTitle($request), 'OPAQUE'),
				'lastModified' => $request->getUpdatedAt()?->getTimestamp(),
			];
		}
		return $events;
	}

	/**
	 * Whether the account gets the team calendar: employees and HR, while it is
	 * switched on and the app is set up.
	 */
	public function hasTeamCalendar(string $uid): bool {
		return $this->config->isCalDavSharedEnabled()
			&& $this->config->isConfigured()
			&& $this->permission->isEmployeeOrHr($uid);
	}

	/**
	 * Display name of the team calendar, in the viewer's language.
	 */
	public function teamCalendarName(string $uid): string {
		return $this->personalL10n($uid)->t('Team absences');
	}

	/**
	 * The events of the viewer's team calendar, by object name: one all-day
	 * event per leave in force of an employee that overlaps the previous, the
	 * current or the next year. They are transparent, so they never make the
	 * viewer busy.
	 *
	 * @return array<string, array{ics: string, lastModified: int|null}>
	 */
	public function teamEvents(string $uid): array {
		if (!$this->hasTeamCalendar($uid)) {
			return [];
		}
		$year = $this->clock->serverYear();
		$requests = $this->requestMapper->findInForceBetween(($year - 1) . '-01-01', ($year + 1) . '-12-31');
		$names = $this->employees->activeDisplayNames();
		$l = $this->personalL10n($uid);
		$reveal = $this->config->getSharedCalendarVisibility() === ConfigService::VISIBILITY_REVEAL;
		$events = [];
		foreach ($requests as $request) {
			$name = $names[$request->getEmployeeUid()] ?? null;
			if ($name === null) {
				continue;
			}
			$eventUid = 'absence-team-' . $request->getId();
			$events[$eventUid . '.ics'] = [
				'ics' => $this->buildIcs($request, $eventUid, $this->teamTitle($request, $name, $l, $reveal), 'TRANSPARENT'),
				'lastModified' => $request->getUpdatedAt()?->getTimestamp(),
			];
		}
		return $events;
	}

	/**
	 * @param 'OPAQUE'|'TRANSPARENT' $transp whether the event makes its calendar's owner busy
	 */
	private function buildIcs(LeaveRequest $request, string $uid, string $title, string $transp): string {
		$vcalendar = new VCalendar();
		// All-day event: DTEND is exclusive, so add one day to the inclusive end date.
		$end = (new \DateTimeImmutable($request->getEndDate()))->modify('+1 day');
		$properties = [
			'UID' => $uid,
			'SUMMARY' => $title,
			'TRANSP' => $transp,
		];
		// A stable stamp keeps the event, and so its ETag, the same until the leave changes
		if ($request->getUpdatedAt() !== null) {
			$properties['DTSTAMP'] = \DateTimeImmutable::createFromMutable($request->getUpdatedAt())->setTimezone(new \DateTimeZone('UTC'));
		}
		$vevent = $vcalendar->add('VEVENT', $properties);
		$vevent->add('DTSTART', new \DateTimeImmutable($request->getStartDate()), ['VALUE' => 'DATE']);
		$vevent->add('DTEND', $end, ['VALUE' => 'DATE']);
		return $vcalendar->serialize();
	}

	private function personalTitle(LeaveRequest $request): string {
		// The employee's own calendar speaks their language, not the actor's:
		// the event outlives whoever happened to click "approve".
		$l = $this->personalL10n($request->getEmployeeUid());
		// A confidential category (§5.7) never reaches a calendar title — personal
		// calendars get shared, and the category is HR's alone.
		if ($this->isHrOnly($request)) {
			return $l->t('Absence');
		}
		return $this->typeLabel($request, $l) . ' · Absence';
	}

	/**
	 * "Name · Absent", or "Name · <type>" when the admin chose to reveal the
	 * type. The setting does not extend to confidential types (§5.7).
	 */
	private function teamTitle(LeaveRequest $request, string $name, \OCP\IL10N $l, bool $reveal): string {
		if ($reveal && !$this->isHrOnly($request)) {
			return $name . ' · ' . $this->typeLabel($request, $l);
		}
		return $name . ' · ' . $l->t('Absent');
	}

	private function isHrOnly(LeaveRequest $request): bool {
		try {
			return $this->leaveTypeMapper->find($request->getTypeId())->getHrOnly();
		} catch (DoesNotExistException) {
			return false;
		}
	}

	private function personalL10n(string $uid): \OCP\IL10N {
		$user = $this->userManager->get($uid);
		$lang = $user !== null ? $this->l10nFactory->getUserLanguage($user) : null;
		return $this->l10nFactory->get(ConfigService::APP_ID, $lang);
	}

	private function typeLabel(LeaveRequest $request, \OCP\IL10N $l): string {
		try {
			return $this->leaveTypeMapper->find($request->getTypeId())->getLabel();
		} catch (DoesNotExistException) {
			return $l->t('Leave');
		}
	}
}
