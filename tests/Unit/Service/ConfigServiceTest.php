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
use OCA\Absence\Tests\Unit\L10nMockTrait;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigServiceTest extends TestCase {
	use L10nMockTrait;
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
			$this->l10nMock(),
		);
	}

	public function testFloatSettingsUseTypedSetter(): void {
		$this->appConfig->expects($this->once())->method('setValueFloat')
			->with(ConfigService::APP_ID, ConfigLexicon::KEY_DEFAULT_ENTITLEMENT, 27.0);
		$this->service->setAdminValue(ConfigLexicon::KEY_DEFAULT_ENTITLEMENT, '27');
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

	public static function malformedValues(): array {
		return [
			'text for a day count' => [ConfigLexicon::KEY_ESCALATION_WINDOW, 'soon'],
			'fraction for a day count' => [ConfigLexicon::KEY_NOTICE_PERIOD, 2.5],
			'bool for a day count' => [ConfigLexicon::KEY_MAX_CONCURRENT, true],
			'text for vacation days' => [ConfigLexicon::KEY_DEFAULT_ENTITLEMENT, 'many'],
			'half a vacation day' => [ConfigLexicon::KEY_DEFAULT_ENTITLEMENT, '27.5'],
			'half a day of carry-over limit' => [ConfigLexicon::KEY_CARRYOVER_CAP, 2.5],
			'text for a switch' => [ConfigLexicon::KEY_CALDAV_PERSONAL, 'false'],
			'list for the expiry' => [ConfigLexicon::KEY_CARRYOVER_EXPIRY, ['03-31']],
			'list for the employees group' => [ConfigLexicon::KEY_EMPLOYEES_GROUP, ['staff']],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('malformedValues')]
	public function testMalformedValuesAreRejectedNotCoerced(string $key, mixed $value): void {
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->appConfig->expects($this->never())->method($this->anything());
		$this->expectException(ValidationException::class);
		$this->service->setAdminValue($key, $value);
	}

	public static function acceptedValues(): array {
		return [
			'no handbook' => [ConfigLexicon::KEY_HANDBOOK_URL, '', ''],
			'web link' => [ConfigLexicon::KEY_HANDBOOK_URL, 'https://example.org/handbook', 'https://example.org/handbook'],
			'web link with port, query and fragment' => [ConfigLexicon::KEY_HR_HANDBOOK_URL, 'http://example.org:8080/a?b=c#d', 'http://example.org:8080/a?b=c#d'],
			'web link to an IPv6 host' => [ConfigLexicon::KEY_HANDBOOK_URL, 'https://[::1]/handbook', 'https://[::1]/handbook'],
			'path on this server' => [ConfigLexicon::KEY_HANDBOOK_URL, '/apps/collectives/Handbook', '/apps/collectives/Handbook'],
			'path with a later backslash' => [ConfigLexicon::KEY_HR_HANDBOOK_URL, '/a\b', '/a\b'],
			'largest vacation days' => [ConfigLexicon::KEY_DEFAULT_ENTITLEMENT, '9999', 9999.0],
			'largest carry-over limit' => [ConfigLexicon::KEY_CARRYOVER_CAP, 9999, 9999.0],
			'no vacation days' => [ConfigLexicon::KEY_DEFAULT_ENTITLEMENT, 0, 0.0],
			'longest escalation window' => [ConfigLexicon::KEY_ESCALATION_WINDOW, 365, 365],
			'longest reminder lead' => [ConfigLexicon::KEY_REMINDER_LEAD, '365', 365],
			'longest notice period' => [ConfigLexicon::KEY_NOTICE_PERIOD, 365, 365],
			'most people off at once' => [ConfigLexicon::KEY_MAX_CONCURRENT, 9999, 9999],
			'expiry on the leap day' => [ConfigLexicon::KEY_CARRYOVER_EXPIRY, '02-29', '02-29'],
			'expiry on the last day of a short month' => [ConfigLexicon::KEY_CARRYOVER_EXPIRY, '04-30', '04-30'],
			'expiry at the end of the year' => [ConfigLexicon::KEY_CARRYOVER_EXPIRY, '12-31', '12-31'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('acceptedValues')]
	public function testValuesInRangeAreAccepted(string $key, mixed $value, int|float|string $stored): void {
		self::assertSame([$key => $stored], $this->service->normalizeAdminValues([$key => $value]));
	}

	public static function refusedValues(): array {
		return [
			'link with an unclosed bracket' => [ConfigLexicon::KEY_HANDBOOK_URL, 'https://['],
			'link with a percent sign for a host' => [ConfigLexicon::KEY_HANDBOOK_URL, 'https://%'],
			'link with a port and no host' => [ConfigLexicon::KEY_HANDBOOK_URL, 'http://:80'],
			'link with no host' => [ConfigLexicon::KEY_HR_HANDBOOK_URL, 'https://'],
			'link with a path and no host' => [ConfigLexicon::KEY_HR_HANDBOOK_URL, 'https:///handbook'],
			'link with a port out of range' => [ConfigLexicon::KEY_HANDBOOK_URL, 'https://example.org:99999/'],
			'link with a space' => [ConfigLexicon::KEY_HANDBOOK_URL, 'https://example.org/a b'],
			'link with a trailing newline' => [ConfigLexicon::KEY_HANDBOOK_URL, "https://example.org/handbook\n"],
			'javascript link' => [ConfigLexicon::KEY_HANDBOOK_URL, 'javascript:alert(1)'],
			'data link' => [ConfigLexicon::KEY_HR_HANDBOOK_URL, 'data:text/html,hello'],
			'link without a scheme' => [ConfigLexicon::KEY_HANDBOOK_URL, 'example.org/handbook'],
			'path starting with two slashes' => [ConfigLexicon::KEY_HANDBOOK_URL, '//evil.example/x'],
			'path starting with a slash and a backslash' => [ConfigLexicon::KEY_HANDBOOK_URL, '/\evil.example/x'],
			'path with a trailing newline' => [ConfigLexicon::KEY_HR_HANDBOOK_URL, "/handbook\n"],
			'a lone slash' => [ConfigLexicon::KEY_HANDBOOK_URL, '/'],
			'vacation days above the column' => [ConfigLexicon::KEY_DEFAULT_ENTITLEMENT, 10000],
			'huge vacation days' => [ConfigLexicon::KEY_DEFAULT_ENTITLEMENT, '1e300'],
			'vacation days that overflow a float' => [ConfigLexicon::KEY_DEFAULT_ENTITLEMENT, '1e999'],
			'infinite vacation days' => [ConfigLexicon::KEY_DEFAULT_ENTITLEMENT, INF],
			'vacation days that are no number' => [ConfigLexicon::KEY_DEFAULT_ENTITLEMENT, NAN],
			'INF as text' => [ConfigLexicon::KEY_CARRYOVER_CAP, 'INF'],
			'NAN as text' => [ConfigLexicon::KEY_CARRYOVER_CAP, 'NAN'],
			'carry-over limit above the column' => [ConfigLexicon::KEY_CARRYOVER_CAP, 10000.0],
			'negative carry-over limit' => [ConfigLexicon::KEY_CARRYOVER_CAP, -0.5],
			'escalation window over a year' => [ConfigLexicon::KEY_ESCALATION_WINDOW, 366],
			'reminder lead over a year' => [ConfigLexicon::KEY_REMINDER_LEAD, '366'],
			'notice period over a year' => [ConfigLexicon::KEY_NOTICE_PERIOD, PHP_INT_MAX],
			'too many people off at once' => [ConfigLexicon::KEY_MAX_CONCURRENT, 10000],
			'expiry on 30 February' => [ConfigLexicon::KEY_CARRYOVER_EXPIRY, '02-30'],
			'expiry on 31 April' => [ConfigLexicon::KEY_CARRYOVER_EXPIRY, '04-31'],
			'expiry on day zero' => [ConfigLexicon::KEY_CARRYOVER_EXPIRY, '03-00'],
			'expiry in month 13' => [ConfigLexicon::KEY_CARRYOVER_EXPIRY, '13-01'],
			'expiry with a trailing newline' => [ConfigLexicon::KEY_CARRYOVER_EXPIRY, "03-31\n"],
			'expiry with a year' => [ConfigLexicon::KEY_CARRYOVER_EXPIRY, '2027-03-31'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('refusedValues')]
	public function testValuesOutOfRangeAreRefused(string $key, mixed $value): void {
		$this->appConfig->expects($this->never())->method($this->anything());
		$this->expectException(ValidationException::class);
		$this->service->setAdminValue($key, $value);
	}

	public static function storedValuesAboveTheMaximum(): array {
		return [
			'vacation days' => ['getDefaultEntitlement', 'getValueFloat', 1.0e300, 9999.0],
			'carry-over limit' => ['getCarryOverCap', 'getValueFloat', 20000.0, 9999.0],
			'escalation window' => ['getEscalationWindowDays', 'getValueInt', PHP_INT_MAX, 365],
			'reminder lead' => ['getReminderLeadDays', 'getValueInt', 1000, 365],
			'notice period' => ['getNoticePeriodDays', 'getValueInt', 366, 365],
			'people off at once' => ['getMaxConcurrentAbsences', 'getValueInt', 1000000, 9999],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('storedValuesAboveTheMaximum')]
	public function testStoredValueAboveTheMaximumIsReadAsTheMaximum(string $getter, string $read, int|float $stored, int|float $expected): void {
		$this->appConfig->method($read)->willReturn($stored);
		self::assertSame($expected, $this->service->$getter());
	}
}
