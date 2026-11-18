<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration\Fixtures;

use OCP\Activity\IConsumer;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\Server;

/**
 * Stands in for the activity app, which test servers do not have, and records
 * the activities published.
 */
class RecordingActivities implements IConsumer {
	/** @var list<IEvent> */
	public static array $published = [];
	private static bool $registered = false;

	/** Start recording, from an empty record. */
	public static function register(): void {
		if (!self::$registered) {
			Server::get(IManager::class)->registerConsumer(static fn (): self => new self());
			self::$registered = true;
		}
		self::$published = [];
	}

	#[\Override]
	public function receive(IEvent $event) {
		self::$published[] = $event;
	}
}
