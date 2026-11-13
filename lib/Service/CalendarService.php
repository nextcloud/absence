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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;

/**
 * Approved leave in Nextcloud Calendar (spec §10).
 *
 * The shared team calendar is written via the CalDAV backend. Everything there is
 * best-effort and lazily resolved: the dav app's CalDavBackend is fetched from the
 * container inside try/catch so a calendar problem can never block an approval or
 * cancellation.
 *
 * The personal calendar is not stored: {@see \OCA\Absence\DAV\PersonalCalendar}
 * builds it read-only from the leave in force, with {@see personalEvents()}.
 */
class CalendarService {
	private const STORED_PERSONAL_CALENDAR_URI = 'absence';
	private const SHARED_CALENDAR_URI = 'absence-team';
	private const SHARED_PRINCIPAL = 'principals/system/absence';
	private const USER_PRINCIPAL_PREFIX = 'principals/users/';
	private const CLEANUP_BATCH_SIZE = 500;

	public function __construct(
		private ContainerInterface $container,
		private ConfigService $config,
		private LeaveRequestMapper $requestMapper,
		private LeaveTypeMapper $leaveTypeMapper,
		private IUserManager $userManager,
		private IFactory $l10nFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Create the team calendar event for an approved request and return the stored
	 * reference ("calendarId:objectUri"), or null when disabled/unavailable. Without
	 * a reference the calendar sync job tries again later.
	 */
	public function onApproved(LeaveRequest $request): ?string {
		if (!$this->config->isCalDavSharedEnabled()) {
			return null;
		}
		$backend = $this->backend();
		if ($backend === null) {
			return null;
		}
		try {
			return $this->writeEvent(
				$backend,
				self::SHARED_PRINCIPAL,
				self::SHARED_CALENDAR_URI,
				$this->sharedL10n()->t('Team absences'),
				$request,
				$this->sharedTitle($request),
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Absence: calendar write failed', ['exception' => $e]);
			return null;
		}
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
				'ics' => $this->buildIcs($request, $eventUid, $this->personalTitle($request)),
				'lastModified' => $request->getUpdatedAt()?->getTimestamp(),
			];
		}
		return $events;
	}

	/**
	 * Remove the personal calendar events earlier versions stored in each
	 * employee's own "absence" calendar, and that calendar once nothing else is
	 * in it. Their references go from the requests; the team calendar's stay.
	 * Safe to run again.
	 */
	public function removeStoredPersonalEvents(): void {
		$backend = $this->backend();
		if ($backend === null) {
			return;
		}
		$teamCalendarId = $backend->getCalendarByUri(self::SHARED_PRINCIPAL, self::SHARED_CALENDAR_URI)['id'] ?? null;
		$personalCalendarIds = [];
		$afterId = 0;
		do {
			$requests = $this->requestMapper->findWithCalendarEventUri($afterId, self::CLEANUP_BATCH_SIZE);
			foreach ($requests as $request) {
				$afterId = $request->getId();
				$kept = [];
				$removed = [];
				foreach (explode(';', (string)$request->getCalendarEventUri()) as $entry) {
					[$calendarId] = explode(':', $entry, 2);
					if ($teamCalendarId !== null && (int)$calendarId === (int)$teamCalendarId) {
						$kept[] = $entry;
					} else {
						$removed[] = $entry;
						$personalCalendarIds[(int)$calendarId] = true;
					}
				}
				if ($removed !== []) {
					$this->deleteEvents($backend, implode(';', $removed), true);
					$request->setCalendarEventUri($kept === [] ? null : implode(';', $kept));
					$this->requestMapper->update($request);
				}
			}
		} while (count($requests) === self::CLEANUP_BATCH_SIZE);

		foreach (array_keys($personalCalendarIds) as $calendarId) {
			try {
				$calendar = $backend->getCalendarById($calendarId);
				if ($calendar === null
					|| $calendar['uri'] !== self::STORED_PERSONAL_CALENDAR_URI
					|| !str_starts_with((string)$calendar['principaluri'], self::USER_PRINCIPAL_PREFIX)
					// Events the owner added are theirs to keep, and so is the calendar
					|| $backend->getCalendarObjects($calendarId) !== []) {
					continue;
				}
				$backend->deleteCalendar($calendarId, true);
			} catch (\Throwable $e) {
				$this->logger->warning('Absence: removing a stored personal calendar failed', ['exception' => $e]);
			}
		}
	}

	/**
	 * Shares the team calendar read-only with the employees group and the HR
	 * group, and with nobody else (§10), creating the calendar if needed. Safe
	 * to run again; does nothing while the team calendar is off or the app is
	 * not set up.
	 */
	public function shareTeamCalendar(): void {
		$backend = $this->backend();
		if ($backend === null || !$this->config->isCalDavSharedEnabled() || !$this->config->isConfigured()) {
			return;
		}
		try {
			$calendarId = $this->ensureCalendar($backend, self::SHARED_PRINCIPAL, self::SHARED_CALENDAR_URI, $this->sharedL10n()->t('Team absences'));
			if ($calendarId === null) {
				return;
			}
			$wanted = array_values(array_unique(array_map(
				static fn (string $gid): string => 'principal:principals/groups/' . $gid,
				[$this->config->getEmployeesGroup(), $this->config->getHrGroup()],
			)));
			$current = array_column($backend->getShares($calendarId), 'href');
			$add = array_map(static fn (string $href): array => ['href' => $href, 'readOnly' => true], array_values(array_diff($wanted, $current)));
			$remove = array_values(array_diff($current, $wanted));
			if ($add === [] && $remove === []) {
				return;
			}
			$calendar = new \OCA\DAV\CalDAV\Calendar($backend, $backend->getCalendarById($calendarId), $this->sharedL10n(), $this->container->get(\OCP\IConfig::class), $this->logger);
			$backend->updateShares($calendar, $add, $remove);
		} catch (\Throwable $e) {
			$this->logger->warning('Absence: sharing the team calendar failed', ['exception' => $e]);
		}
	}

	/**
	 * Remove any calendar events previously created for a request.
	 */
	public function onRemoved(LeaveRequest $request): void {
		$backend = $this->backend();
		$ref = $request->getCalendarEventUri();
		if ($backend === null || $ref === null || $ref === '') {
			return;
		}
		$this->deleteEvents($backend, $ref);
	}

	private function deleteEvents($backend, string $ref, bool $permanently = false): void {
		foreach (explode(';', $ref) as $entry) {
			[$calendarId, $objectUri] = array_pad(explode(':', $entry, 2), 2, null);
			if ($calendarId === null || $objectUri === null) {
				continue;
			}
			try {
				$backend->deleteCalendarObject((int)$calendarId, $objectUri, \OCA\DAV\CalDAV\CalDavBackend::CALENDAR_TYPE_CALENDAR, $permanently);
			} catch (\Throwable $e) {
				$this->logger->debug('Absence: calendar delete failed', ['exception' => $e]);
			}
		}
	}

	private function writeEvent($backend, string $principalUri, string $calendarUri, string $displayName, LeaveRequest $request, string $title): ?string {
		$calendarId = $this->ensureCalendar($backend, $principalUri, $calendarUri, $displayName);
		if ($calendarId === null) {
			return null;
		}
		$objectUri = 'absence-' . $request->getId() . '-' . substr(md5($principalUri), 0, 8) . '.ics';
		$ics = $this->buildIcs($request, $objectUri, $title);
		$backend->createCalendarObject($calendarId, $objectUri, $ics);
		return $calendarId . ':' . $objectUri;
	}

	private function ensureCalendar($backend, string $principalUri, string $uri, string $displayName): ?int {
		try {
			foreach ($backend->getCalendarsForUser($principalUri) as $calendar) {
				if (($calendar['uri'] ?? null) === $uri) {
					return (int)$calendar['id'];
				}
			}
			return (int)$backend->createCalendar($principalUri, $uri, [
				'{DAV:}displayname' => $displayName,
				'{http://apple.com/ns/ical/}calendar-color' => '#0082c9',
			]);
		} catch (\Throwable $e) {
			$this->logger->debug('Absence: ensureCalendar failed', ['exception' => $e]);
			return null;
		}
	}

	private function buildIcs(LeaveRequest $request, string $uid, string $title): string {
		$vcalendar = new VCalendar();
		// All-day event: DTEND is exclusive, so add one day to the inclusive end date.
		$end = (new \DateTimeImmutable($request->getEndDate()))->modify('+1 day');
		$properties = [
			'UID' => $uid,
			'SUMMARY' => $title,
			'TRANSP' => 'OPAQUE',
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
		return $this->typeLabel($request, $l) . ' — Absence';
	}

	private function sharedTitle(LeaveRequest $request): string {
		// The shared calendar has every colleague as its audience, so it uses the
		// instance default language rather than any one person's.
		$l = $this->sharedL10n();
		$name = $this->displayName($request->getEmployeeUid());
		// The admin's "reveal" setting does not extend to confidential types (§5.7).
		if ($this->config->getSharedCalendarVisibility() === ConfigService::VISIBILITY_REVEAL && !$this->isHrOnly($request)) {
			return $name . ' — ' . $this->typeLabel($request, $l);
		}
		return $name . ' — ' . $l->t('Absent');
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

	private function sharedL10n(): \OCP\IL10N {
		return $this->l10nFactory->get(ConfigService::APP_ID, $this->l10nFactory->findGenericLanguage(ConfigService::APP_ID));
	}

	private function typeLabel(LeaveRequest $request, \OCP\IL10N $l): string {
		try {
			return $this->leaveTypeMapper->find($request->getTypeId())->getLabel();
		} catch (DoesNotExistException) {
			return $l->t('Leave');
		}
	}

	private function displayName(string $uid): string {
		$user = $this->userManager->get($uid);
		return $user !== null ? $user->getDisplayName() : $uid;
	}

	private function backend(): mixed {
		try {
			return $this->container->get(\OCA\DAV\CalDAV\CalDavBackend::class);
		} catch (\Throwable $e) {
			$this->logger->debug('Absence: CalDavBackend unavailable', ['exception' => $e]);
			return null;
		}
	}
}
