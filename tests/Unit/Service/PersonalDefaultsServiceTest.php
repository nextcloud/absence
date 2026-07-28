<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\PersonalDefaultsService;
use OCA\DAV\Db\PropertyMapper;
use OCP\Accounts\IAccountManager;
use OCP\Config\IUserConfig;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PersonalDefaultsServiceTest extends TestCase {
	private IUserConfig&MockObject $userConfig;
	private IUserManager&MockObject $userManager;
	private PersonalDefaultsService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->userConfig = $this->createMock(IUserConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->service = new PersonalDefaultsService(
			$this->userConfig,
			$this->createMock(ConfigService::class),
			$this->createMock(IAccountManager::class),
			$this->userManager,
			$this->createMock(PropertyMapper::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testDetectCountryReadsLocaleFromUserConfig(): void {
		$this->userConfig->expects($this->once())
			->method('getValueString')
			->with('alice', 'core', 'locale')
			->willReturn('de_AT');

		$this->assertSame('AT', $this->service->detectCountry('alice'));
	}

	public function testDetectCountryFallsBackToPhoneWhenLocaleHasNoRegion(): void {
		$this->userConfig->method('getValueString')->willReturn('de');
		// No user -> phone lookup yields nothing, so the result is null rather than a guess.
		$this->userManager->method('get')->with('alice')->willReturn(null);

		$this->assertNull($this->service->detectCountry('alice'));
	}

	public function testDetectCountryFallsBackToPhoneWhenLocaleUnset(): void {
		$this->userConfig->method('getValueString')->willReturn('');
		$this->userManager->method('get')->with('alice')->willReturn(null);

		$this->assertNull($this->service->detectCountry('alice'));
	}
}
