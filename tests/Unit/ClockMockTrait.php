<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit;

use OCA\Absence\Service\ClockService;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * A ClockService that answers exactly what a bare date('Y-m-d') used to, so
 * tests written before the clock existed keep the same meaning. Tests that care
 * about timezone boundaries pin their own instant — see ClockServiceTest.
 */
trait ClockMockTrait {
	/** @return ClockService&MockObject */
	private function clockAtRealTime(): ClockService {
		$clock = $this->createMock(ClockService::class);
		$clock->method('userToday')->willReturn(date('Y-m-d'));
		$clock->method('serverToday')->willReturn(date('Y-m-d'));
		$clock->method('userYear')->willReturn((int)date('Y'));
		$clock->method('serverYear')->willReturn((int)date('Y'));
		$clock->method('userNow')->willReturn(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
		$clock->method('serverNow')->willReturn(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

		return $clock;
	}
}
