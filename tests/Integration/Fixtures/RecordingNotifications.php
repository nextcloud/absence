<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration\Fixtures;

use OCP\Notification\IApp;
use OCP\Notification\INotification;

/**
 * Stands in for the notifications app, which test servers do not have, and
 * records what the absence app sends and takes back.
 */
class RecordingNotifications implements IApp {
	/** @var list<INotification> */
	public static array $sent = [];
	/** @var list<INotification> */
	public static array $processed = [];

	#[\Override]
	public function notify(INotification $notification): void {
		self::$sent[] = $notification;
	}

	#[\Override]
	public function markProcessed(INotification $notification): void {
		self::$processed[] = $notification;
	}

	#[\Override]
	public function getCount(INotification $notification): int {
		return 0;
	}
}
