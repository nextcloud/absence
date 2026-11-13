<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\DAV;

use OCA\Absence\Service\CalendarService;
use OCA\Absence\Service\ConfigService;
use OCA\DAV\CalDAV\Integration\ExternalCalendar;
use Sabre\CalDAV\CalendarQueryValidator;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\PropPatch;
use Sabre\VObject\Reader;

/**
 * An employee's personal Absence calendar: their leave in force, built from the
 * requests on every read (§10). Nobody can write events to it; the owner may set
 * properties such as colour and visibility, which the server stores per user.
 */
class PersonalCalendar extends ExternalCalendar {
	private const COLOR = '#0082c9';
	private const CTAG = '{http://calendarserver.org/ns/}getctag';

	/** @var array<string, array{ics: string, lastModified: int|null}>|null */
	private ?array $events = null;

	public function __construct(
		private string $principalUri,
		private string $uid,
		private CalendarService $calendar,
	) {
		parent::__construct(ConfigService::APP_ID, CalendarPlugin::CALENDAR_URI);
	}

	#[\Override]
	public function getOwner() {
		return $this->principalUri;
	}

	#[\Override]
	public function getGroup() {
		return null;
	}

	#[\Override]
	public function getACL() {
		return array_merge($this->readAcl(), [[
			'privilege' => '{DAV:}write-properties',
			'principal' => $this->principalUri,
			'protected' => true,
		]]);
	}

	/**
	 * Read access for the owner and their calendar delegates.
	 *
	 * @return list<array{privilege: string, principal: string, protected: bool}>
	 */
	public function readAcl(): array {
		return array_map(static fn (string $principal): array => [
			'privilege' => '{DAV:}read',
			'principal' => $principal,
			'protected' => true,
		], [
			$this->principalUri,
			$this->principalUri . '/calendar-proxy-read',
			$this->principalUri . '/calendar-proxy-write',
		]);
	}

	#[\Override]
	public function setACL(array $acl) {
		throw new Forbidden('Setting ACL is not supported on this node');
	}

	#[\Override]
	public function getSupportedPrivilegeSet() {
		return null;
	}

	#[\Override]
	public function createFile($name, $data = null) {
		throw new Forbidden('The Absence calendar is read-only');
	}

	#[\Override]
	public function delete() {
		throw new Forbidden('The Absence calendar is read-only');
	}

	#[\Override]
	public function getChild($name) {
		$event = $this->events()[$name] ?? null;
		if ($event === null) {
			throw new NotFound('Node not found');
		}
		return new LeaveEvent($this, $name, $event['ics'], $event['lastModified']);
	}

	#[\Override]
	public function getChildren() {
		return array_map(fn (string $name): LeaveEvent => $this->getChild($name), array_keys($this->events()));
	}

	#[\Override]
	public function childExists($name) {
		return isset($this->events()[$name]);
	}

	#[\Override]
	public function calendarQuery(array $filters) {
		$validator = new CalendarQueryValidator();
		$names = [];
		foreach ($this->events() as $name => $event) {
			$vObject = Reader::read($event['ics']);
			if ($validator->validate($vObject, $filters)) {
				$names[] = $name;
			}
			$vObject->destroy();
		}
		return $names;
	}

	#[\Override]
	public function getLastModified() {
		$times = array_filter(array_column($this->events(), 'lastModified'));
		return $times === [] ? null : max($times);
	}

	#[\Override]
	public function propPatch(PropPatch $propPatch) {
		// Left to the server's property storage, which keeps them per user
	}

	#[\Override]
	public function getProperties($properties) {
		$result = [
			'{DAV:}displayname' => $this->calendar->personalCalendarName($this->uid),
			'{http://apple.com/ns/ical/}calendar-color' => self::COLOR,
			'{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT']),
		];
		if (in_array(self::CTAG, $properties, true)) {
			$result[self::CTAG] = md5(implode(';', array_map(
				static fn (string $name, array $event): string => $name . ':' . md5($event['ics']),
				array_keys($this->events()),
				$this->events(),
			)));
		}
		return $result;
	}

	/**
	 * @return array<string, array{ics: string, lastModified: int|null}>
	 */
	private function events(): array {
		return $this->events ??= $this->calendar->personalEvents($this->uid);
	}
}
