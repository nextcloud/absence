<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit;

use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * An IL10N whose t() interpolates for real, so a mismatch between a format
 * string and its arguments fails the test instead of reaching a user.
 */
trait L10nMockTrait {
	private function l10nMock(): IL10N&MockObject {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters),
		);
		return $l;
	}
}
