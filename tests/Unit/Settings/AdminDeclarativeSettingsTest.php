<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Settings;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Settings\AdminDeclarativeSettings;
use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\Preset;
use OCP\IL10N;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AdminDeclarativeSettingsTest extends TestCase {
	private ConfigService&MockObject $config;
	private IUser&MockObject $user;
	private AdminDeclarativeSettings $form;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(ConfigService::class);
		$this->user = $this->createMock(IUser::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text) => $text);
		$this->form = new AdminDeclarativeSettings(
			$this->config,
			$l,
			$this->createMock(LoggerInterface::class),
		);
	}

	/** @return array<string,array<string,mixed>> */
	private function fieldsById(): array {
		$byId = [];
		foreach ($this->form->getSchema()['fields'] as $field) {
			$byId[$field['id']] = $field;
		}
		return $byId;
	}

	public function testSchemaTargetsTheAbsenceAdminSection(): void {
		$schema = $this->form->getSchema();
		$this->assertSame(DeclarativeSettingsTypes::SECTION_TYPE_ADMIN, $schema['section_type']);
		$this->assertSame(ConfigService::APP_ID, $schema['section_id']);
		$this->assertSame(DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL, $schema['storage_type']);
	}

	public function testEveryAdminSettingHasExactlyOneField(): void {
		$this->config->method('getAdminConfig')->willReturn(self::adminConfigFixture());
		$this->assertSame(
			array_keys(self::adminConfigFixture()),
			array_keys($this->fieldsById()),
			'Form fields must cover exactly the admin settings, in the same order',
		);
	}

	public function testFieldDefaultsMatchTheConfigLexicon(): void {
		$lexiconDefaults = [];
		foreach ((new ConfigLexicon())->getAppConfigs() as $entry) {
			/** @var Entry $entry */
			$lexiconDefaults[$entry->getKey()] = $entry->getDefault(Preset::NONE);
		}
		foreach ($this->fieldsById() as $id => $field) {
			$this->assertArrayHasKey($id, $lexiconDefaults, "Field $id has no lexicon entry");
			// getDefault() returns the stringified default ('1'/'0' for booleans).
			$default = $field['default'];
			$normalized = is_bool($default) ? ($default ? '1' : '0') : (string)$default;
			$this->assertSame($lexiconDefaults[$id], $normalized,
				"Default of field $id differs from the config lexicon");
		}
	}

	public function testSelectAndRadioDefaultsAreValidOptions(): void {
		foreach ($this->fieldsById() as $id => $field) {
			if (!isset($field['options'])) {
				continue;
			}
			$values = array_column($field['options'], 'value');
			$this->assertContains($field['default'], $values,
				"Default of field $id is not one of its options");
		}
	}

	public function testGetValueReadsFromConfigService(): void {
		$this->config->method('getAdminConfig')->willReturn(self::adminConfigFixture());
		$this->assertSame('hr', $this->form->getValue(ConfigLexicon::KEY_HR_GROUP, $this->user));
		$this->assertSame(25.0, $this->form->getValue(ConfigLexicon::KEY_DEFAULT_ENTITLEMENT, $this->user));
	}

	public function testGetValueRejectsUnknownFields(): void {
		$this->config->method('getAdminConfig')->willReturn(self::adminConfigFixture());
		$this->expectException(\InvalidArgumentException::class);
		$this->form->getValue('nope', $this->user);
	}

	public function testSetValueDelegatesToConfigService(): void {
		$this->config->expects($this->once())->method('setAdminValue')
			->with(ConfigLexicon::KEY_MAX_CONCURRENT, 4);
		$this->user->method('getUID')->willReturn('admin');
		$this->form->setValue(ConfigLexicon::KEY_MAX_CONCURRENT, 4, $this->user);
	}

	/** @return array<string,mixed> Shape of ConfigService::getAdminConfig(). */
	private static function adminConfigFixture(): array {
		return [
			ConfigLexicon::KEY_HR_GROUP => 'hr',
			ConfigLexicon::KEY_DEFAULT_ENTITLEMENT => 25.0,
			ConfigLexicon::KEY_ESCALATION_WINDOW => 3,
			ConfigLexicon::KEY_REMINDER_LEAD => 1,
			ConfigLexicon::KEY_CARRYOVER_POLICY => ConfigService::CARRYOVER_CAPPED,
			ConfigLexicon::KEY_CARRYOVER_CAP => 5.0,
			ConfigLexicon::KEY_CARRYOVER_EXPIRY => '',
			ConfigLexicon::KEY_MAX_CONCURRENT => 2,
			ConfigLexicon::KEY_CALDAV_PERSONAL => true,
			ConfigLexicon::KEY_CALDAV_SHARED => true,
			ConfigLexicon::KEY_SHARED_VISIBILITY => ConfigService::VISIBILITY_NEUTRAL,
		];
	}
}
