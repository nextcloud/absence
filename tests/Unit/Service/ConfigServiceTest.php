<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\ConfigService;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigServiceTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private IGroupManager&MockObject $groupManager;
	private ConfigService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->service = new ConfigService(
			$this->appConfig,
			$this->createMock(IUserConfig::class),
			$this->groupManager,
		);
	}

	public function testFloatSettingsUseTypedSetter(): void {
		$this->appConfig->expects($this->once())->method('setValueFloat')
			->with(ConfigService::APP_ID, ConfigLexicon::KEY_DEFAULT_ENTITLEMENT, 27.5);
		$this->service->setAdminValue(ConfigLexicon::KEY_DEFAULT_ENTITLEMENT, '27.5');
	}

	public function testIntSettingsUseTypedSetter(): void {
		$this->appConfig->expects($this->once())->method('setValueInt')
			->with(ConfigService::APP_ID, ConfigLexicon::KEY_ESCALATION_WINDOW, 5);
		$this->service->setAdminValue(ConfigLexicon::KEY_ESCALATION_WINDOW, '5');
	}

	public function testNegativeNumbersAreRejected(): void {
		$this->appConfig->expects($this->never())->method('setValueInt');
		$this->expectException(ValidationException::class);
		$this->service->setAdminValue(ConfigLexicon::KEY_ESCALATION_WINDOW, -1);
	}

	public function testInvalidCarryOverPolicyIsRejected(): void {
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->expectException(ValidationException::class);
		$this->service->setAdminValue(ConfigLexicon::KEY_CARRYOVER_POLICY, 'banana');
	}

	public function testValidCarryOverPolicyIsStored(): void {
		$this->appConfig->expects($this->once())->method('setValueString')
			->with(ConfigService::APP_ID, ConfigLexicon::KEY_CARRYOVER_POLICY, ConfigService::CARRYOVER_NONE);
		$this->service->setAdminValue(ConfigLexicon::KEY_CARRYOVER_POLICY, ConfigService::CARRYOVER_NONE);
	}

	public function testInvalidSharedVisibilityIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->service->setAdminValue(ConfigLexicon::KEY_SHARED_VISIBILITY, 'everything');
	}

	public function testMalformedCarryOverExpiryIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->service->setAdminValue(ConfigLexicon::KEY_CARRYOVER_EXPIRY, '13-45');
	}

	public function testEmptyCarryOverExpiryIsAllowed(): void {
		$this->appConfig->expects($this->once())->method('setValueString')
			->with(ConfigService::APP_ID, ConfigLexicon::KEY_CARRYOVER_EXPIRY, '');
		$this->service->setAdminValue(ConfigLexicon::KEY_CARRYOVER_EXPIRY, '');
	}

	public function testValidCarryOverExpiryIsStored(): void {
		$this->appConfig->expects($this->once())->method('setValueString')
			->with(ConfigService::APP_ID, ConfigLexicon::KEY_CARRYOVER_EXPIRY, '03-31');
		$this->service->setAdminValue(ConfigLexicon::KEY_CARRYOVER_EXPIRY, '03-31');
	}

	public function testUnknownKeysAreRejected(): void {
		$this->expectException(ValidationException::class);
		$this->service->setAdminValue('some_other_key', 'value');
	}

	public function testBoolSettingsUseTypedSetter(): void {
		$this->appConfig->expects($this->once())->method('setValueBool')
			->with(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_SHARED, false);
		$this->service->setAdminValue(ConfigLexicon::KEY_CALDAV_SHARED, false);
	}

	public function testExistingHrGroupIsStored(): void {
		$this->groupManager->method('groupExists')->with('hr-team')->willReturn(true);
		$this->appConfig->expects($this->once())->method('setValueString')
			->with(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, 'hr-team');
		$this->service->setAdminValue(ConfigLexicon::KEY_HR_GROUP, 'hr-team');
	}

	public function testUnknownHrGroupIsRejected(): void {
		$this->groupManager->method('groupExists')->with('ghosts')->willReturn(false);
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->expectException(ValidationException::class);
		$this->service->setAdminValue(ConfigLexicon::KEY_HR_GROUP, 'ghosts');
	}

	public function testEmptyHrGroupIsRejected(): void {
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->expectException(ValidationException::class);
		$this->service->setAdminValue(ConfigLexicon::KEY_HR_GROUP, '');
	}
}
