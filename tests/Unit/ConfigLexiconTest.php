<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Service\ConfigService;
use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\Preset;
use OCP\Config\ValueType;
use PHPUnit\Framework\TestCase;

class ConfigLexiconTest extends TestCase {
	private ConfigLexicon $lexicon;

	protected function setUp(): void {
		parent::setUp();
		$this->lexicon = new ConfigLexicon();
	}

	/** @return array<string,Entry> */
	private function entriesByKey(array $entries): array {
		$byKey = [];
		foreach ($entries as $entry) {
			$byKey[$entry->getKey()] = $entry;
		}
		return $byKey;
	}

	public function testDeclaresAllAdminAndInternalKeys(): void {
		$keys = array_keys($this->entriesByKey($this->lexicon->getAppConfigs()));
		sort($keys);
		$expected = [
			ConfigLexicon::KEY_CALDAV_PERSONAL,
			ConfigLexicon::KEY_CALDAV_SHARED,
			ConfigLexicon::KEY_CARRYOVER_CAP,
			ConfigLexicon::KEY_CARRYOVER_EXPIRY,
			ConfigLexicon::KEY_CARRYOVER_POLICY,
			ConfigLexicon::KEY_DEFAULT_ENTITLEMENT,
			ConfigLexicon::KEY_ESCALATION_WINDOW,
			ConfigLexicon::KEY_HR_GROUP,
			ConfigLexicon::KEY_LAST_ROLLOVER_YEAR,
			ConfigLexicon::KEY_MAX_CONCURRENT,
			ConfigLexicon::KEY_REMINDER_LEAD,
			ConfigLexicon::KEY_SHARED_VISIBILITY,
		];
		sort($expected);
		$this->assertSame($expected, $keys);
	}

	public function testDeclaresAllUserKeys(): void {
		$keys = array_keys($this->entriesByKey($this->lexicon->getUserConfigs()));
		sort($keys);
		$this->assertSame([
			ConfigLexicon::KEY_HOLIDAY_COUNTRY,
			ConfigLexicon::KEY_HOLIDAY_REGION,
			ConfigLexicon::KEY_WORK_WEEKDAYS,
		], $keys);
	}

	public function testDefaultsAndTypesMatchTheDocumentedBehaviour(): void {
		$entries = $this->entriesByKey($this->lexicon->getAppConfigs());

		$this->assertSame(ValueType::STRING, $entries[ConfigLexicon::KEY_HR_GROUP]->getValueType());
		$this->assertSame('hr', $entries[ConfigLexicon::KEY_HR_GROUP]->getDefault(Preset::NONE));

		$this->assertSame(ValueType::FLOAT, $entries[ConfigLexicon::KEY_DEFAULT_ENTITLEMENT]->getValueType());
		$this->assertSame('25', $entries[ConfigLexicon::KEY_DEFAULT_ENTITLEMENT]->getDefault(Preset::NONE));

		$this->assertSame(ValueType::FLOAT, $entries[ConfigLexicon::KEY_CARRYOVER_CAP]->getValueType());
		$this->assertSame(ValueType::INT, $entries[ConfigLexicon::KEY_ESCALATION_WINDOW]->getValueType());
		$this->assertSame('3', $entries[ConfigLexicon::KEY_ESCALATION_WINDOW]->getDefault(Preset::NONE));

		$this->assertSame(ConfigService::CARRYOVER_CAPPED, $entries[ConfigLexicon::KEY_CARRYOVER_POLICY]->getDefault(Preset::NONE));
		$this->assertSame(ConfigService::VISIBILITY_NEUTRAL, $entries[ConfigLexicon::KEY_SHARED_VISIBILITY]->getDefault(Preset::NONE));

		$this->assertSame(ValueType::BOOL, $entries[ConfigLexicon::KEY_CALDAV_PERSONAL]->getValueType());
		$this->assertSame('1', $entries[ConfigLexicon::KEY_CALDAV_PERSONAL]->getDefault(Preset::NONE));
	}
}
