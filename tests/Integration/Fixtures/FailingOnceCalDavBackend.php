<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration\Fixtures;

use OCA\DAV\CalDAV\CalDavBackend;

/**
 * Passes everything on to the real calendar backend, except that one event
 * write can be made to fail, and that a test can act right after a write.
 */
class FailingOnceCalDavBackend {
	private int $writes = 0;

	/**
	 * @param int $failOnWrite the write (1 = first) that throws, 0 for none
	 * @param ?\Closure(int, string): void $onWrite called with the calendar id and
	 *                                              object uri after each write that went through
	 */
	public function __construct(
		private CalDavBackend $backend,
		private int $failOnWrite = 0,
		private ?\Closure $onWrite = null,
	) {
	}

	public function createCalendarObject(mixed ...$arguments): mixed {
		if (++$this->writes === $this->failOnWrite) {
			throw new \RuntimeException('Calendar write failed for the test');
		}
		$etag = $this->backend->createCalendarObject(...$arguments);
		if ($this->onWrite !== null) {
			($this->onWrite)((int)$arguments[0], (string)$arguments[1]);
		}
		return $etag;
	}

	public function __call(string $name, array $arguments): mixed {
		return $this->backend->$name(...$arguments);
	}
}
