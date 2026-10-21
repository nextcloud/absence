<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Service\ImportService;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * User resolution of the imports against the real user manager, whose lookup
 * also accepts an e-mail address (§6.3).
 */
class ImportServiceTest extends TestCase {
	private const UID = 'itest-import-robin';
	private const EMAIL = 'robin.import@example.com';

	protected function setUp(): void {
		parent::setUp();
		$users = Server::get(IUserManager::class);
		$user = $users->get(self::UID) ?? $users->createUser(self::UID, 'itest-' . bin2hex(random_bytes(8)));
		self::assertNotFalse($user);
		$user->setSystemEMailAddress(self::EMAIL);
	}

	protected function tearDown(): void {
		Server::get(IUserManager::class)->get(self::UID)?->delete();
		parent::tearDown();
	}

	public function testEmailResolvesToTheUid(): void {
		$plan = Server::get(ImportService::class)->plan("user,base_days\n" . self::EMAIL . ",25\n" . self::UID . ',20', 2025);

		self::assertSame([self::UID, self::UID], array_column($plan, 'uid'));
	}
}
