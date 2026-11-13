<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\DAV;

use Sabre\CalDAV\ICalendarObject;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAVACL\IACL;

/**
 * One leave in the personal Absence calendar. Read-only.
 */
class LeaveEvent implements ICalendarObject, IACL {
	public function __construct(
		private PersonalCalendar $calendar,
		private string $name,
		private string $ics,
		private ?int $lastModified,
	) {
	}

	#[\Override]
	public function getOwner() {
		return $this->calendar->getOwner();
	}

	#[\Override]
	public function getGroup() {
		return null;
	}

	#[\Override]
	public function getACL() {
		return $this->calendar->readAcl();
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
	public function put($data) {
		throw new Forbidden('Leave in the Absence calendar is changed in the Absence app');
	}

	#[\Override]
	public function get() {
		return $this->ics;
	}

	#[\Override]
	public function getContentType() {
		return 'text/calendar; charset=utf-8; component=vevent';
	}

	#[\Override]
	public function getETag() {
		return '"' . md5($this->ics) . '"';
	}

	#[\Override]
	public function getSize() {
		return strlen($this->ics);
	}

	#[\Override]
	public function delete() {
		throw new Forbidden('Leave in the Absence calendar is changed in the Absence app');
	}

	#[\Override]
	public function getName() {
		return $this->name;
	}

	#[\Override]
	public function setName($name) {
		throw new Forbidden('Leave in the Absence calendar is changed in the Absence app');
	}

	#[\Override]
	public function getLastModified() {
		return $this->lastModified;
	}
}
