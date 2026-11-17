<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Migration\Version1016Date20261117140000;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\PermissionService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Nobody holds the HR role until an admin chooses the group (§2), and an
 * upgrade keeps the `hr` group an install relied on.
 */
class HrGroupDefaultTest extends TestCase {
	private const MEMBER = 'itest-hrdefault-member';
	private const OTHER_GROUP = 'itest-hrdefault-other';

	private ?string $stored = null;
	private bool $createdGroup = false;

	protected function setUp(): void {
		parent::setUp();
		$appConfig = Server::get(IAppConfig::class);
		if ($appConfig->hasKey(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP)) {
			$this->stored = $appConfig->getValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP);
		}
		$appConfig->deleteKey(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP);

		$groups = Server::get(IGroupManager::class);
		if (!$groups->groupExists('hr')) {
			$groups->createGroup('hr');
			$this->createdGroup = true;
		}
		$groups->get('hr')?->addUser(Server::get(IUserManager::class)->createUser(self::MEMBER, 'Itest-Password-hrdefault'));
	}

	protected function tearDown(): void {
		Server::get(IUserManager::class)->get(self::MEMBER)?->delete();
		if ($this->createdGroup) {
			Server::get(IGroupManager::class)->get('hr')?->delete();
		}
		$appConfig = Server::get(IAppConfig::class);
		$appConfig->deleteKey(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP);
		if ($this->stored !== null) {
			$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, $this->stored);
		}
		parent::tearDown();
	}

	private function upgrade(): void {
		Server::get(Version1016Date20261117140000::class)->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);
	}

	public function testAGroupCalledHrHoldsNoRoleUntilItIsChosen(): void {
		self::assertSame('', Server::get(ConfigService::class)->getHrGroup());
		self::assertFalse(Server::get(PermissionService::class)->isHr(self::MEMBER));
	}

	public function testAnUpgradeKeepsTheHrGroupTheInstallUsed(): void {
		$this->upgrade();

		self::assertSame('hr', Server::get(ConfigService::class)->getHrGroup());
		self::assertTrue(Server::get(PermissionService::class)->isHr(self::MEMBER));
	}

	public function testAnUpgradeKeepsTheGroupAnAdminChose(): void {
		Server::get(IAppConfig::class)->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, self::OTHER_GROUP);

		$this->upgrade();

		self::assertSame(self::OTHER_GROUP, Server::get(ConfigService::class)->getHrGroup());
	}

	public function testAnUpgradeWithoutAnHrGroupChoosesNone(): void {
		if (!$this->createdGroup) {
			self::markTestSkipped('The instance has its own group "hr"');
		}
		Server::get(IGroupManager::class)->get('hr')?->delete();

		$this->upgrade();

		self::assertFalse(Server::get(IAppConfig::class)->hasKey(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP));
	}
}
