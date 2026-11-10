<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Exception\ValidationException;
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
		$plan = Server::get(ImportService::class)->plan("user,base_days,year\n" . self::EMAIL . ",25,2025\n" . self::UID . ',20,2026', 2025);

		self::assertSame([self::UID, self::UID], array_column($plan, 'uid'));
	}

	public function testAnotherCasingResolvesToTheAccountsUid(): void {
		$plan = Server::get(ImportService::class)->plan("user,base_days\nITEST-Import-Robin,25", 2025);

		self::assertSame([self::UID], array_column($plan, 'uid'));
	}

	public function testARowWithMoreFieldsThanTheHeaderIsRefused(): void {
		// 28,5 written with a decimal comma is two fields
		$message = $this->refusal("user,base_days\n" . self::UID . ',28,5');

		self::assertStringContainsString('line 2: 3 fields, the header has 2', $message);
	}

	public function testARowWithFewerFieldsThanTheHeaderIsRefused(): void {
		$message = $this->refusal("user,base_days,note\n" . self::UID . ',28');

		self::assertStringContainsString('line 2: 2 fields, the header has 3', $message);
	}

	public function testAQuotedNoteMaySpanLines(): void {
		$csv = "user,adjustment,note,year\n"
			. self::UID . ",2,\"Overtime, agreed\nwith the team lead\",2025\n"
			. 'itest-import-nobody,1,x,2025';

		$message = $this->refusal($csv);
		// The row after the note is still counted by the line it starts on
		self::assertSame("Import aborted, nothing was written:\nline 4: unknown user 'itest-import-nobody'", $message);

		$plan = Server::get(ImportService::class)->plan(substr($csv, 0, (int)strrpos($csv, "\n")), 2024);
		self::assertCount(1, $plan);
		self::assertSame("Overtime, agreed\nwith the team lead", $plan[0]['data']['adjustmentNote']);
		self::assertSame(2025, $plan[0]['year']);
	}

	public function testTheSameUserTypeAndYearTwiceIsRefused(): void {
		$message = $this->refusal("user,base_days,year\n" . self::UID . ",25,2025\n" . self::UID . ",26,2026\n" . self::EMAIL . ',27,2025');

		self::assertSame("Import aborted, nothing was written:\nline 4: same user, type and year as line 2", $message);
	}

	private function refusal(string $csv): string {
		try {
			Server::get(ImportService::class)->plan($csv, 2025);
		} catch (ValidationException $e) {
			return $e->getMessage();
		}
		self::fail('The file must be refused');
	}
}
