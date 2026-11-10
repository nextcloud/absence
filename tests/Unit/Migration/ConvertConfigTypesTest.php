<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Migration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Migration\ConvertConfigTypes;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\Exceptions\AppConfigUnknownKeyException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConvertConfigTypesTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private IDBConnection&MockObject $db;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->db = $this->createMock(IDBConnection::class);
	}

	/**
	 * @param array<string, array{value: string, type: int, lazy: bool}> $stored
	 */
	private function stored(array $stored): void {
		$this->appConfig->method('getDetails')->willReturnCallback(
			static fn (string $app, string $key): array => $stored[$key] ?? throw new AppConfigUnknownKeyException(),
		);
	}

	private function runStep(): void {
		(new ConvertConfigTypes($this->appConfig, $this->db))->run($this->createMock(IOutput::class));
	}

	public function testAValueOfTheRightTypeIsLeftAlone(): void {
		$this->stored([
			ConfigLexicon::KEY_HR_GROUP => ['value' => 'itest-hr', 'type' => IAppConfig::VALUE_STRING, 'lazy' => false],
			ConfigLexicon::KEY_ESCALATION_WINDOW => ['value' => '5', 'type' => IAppConfig::VALUE_INT, 'lazy' => true],
			ConfigLexicon::KEY_CARRYOVER_CAP => ['value' => '7.5', 'type' => IAppConfig::VALUE_FLOAT, 'lazy' => true],
			ConfigLexicon::KEY_CALDAV_SHARED => ['value' => '0', 'type' => IAppConfig::VALUE_BOOL, 'lazy' => true],
		]);
		$this->appConfig->expects(self::never())->method('deleteKey');
		$this->appConfig->expects(self::never())->method('setValueString');
		$this->appConfig->expects(self::never())->method('setValueInt');
		$this->appConfig->expects(self::never())->method('setValueFloat');
		$this->appConfig->expects(self::never())->method('setValueBool');

		$this->runStep();
	}

	public function testAnUntypedValueIsWrittenWithItsType(): void {
		$this->stored([
			ConfigLexicon::KEY_HR_GROUP => ['value' => 'itest-hr', 'type' => IAppConfig::VALUE_STRING, 'lazy' => false],
			ConfigLexicon::KEY_ESCALATION_WINDOW => ['value' => '5', 'type' => IAppConfig::VALUE_MIXED, 'lazy' => false],
		]);
		$this->appConfig->expects(self::once())->method('deleteKey')->with('absence', ConfigLexicon::KEY_ESCALATION_WINDOW);
		$this->appConfig->expects(self::once())->method('setValueInt')->with('absence', ConfigLexicon::KEY_ESCALATION_WINDOW, 5);
		$this->db->expects(self::once())->method('commit');

		$this->runStep();
	}

	public function testAFailedWriteKeepsTheStoredValueAndTheUpgradeGoesOn(): void {
		$this->stored([
			ConfigLexicon::KEY_HR_GROUP => ['value' => 'itest-hr', 'type' => IAppConfig::VALUE_MIXED, 'lazy' => false],
			ConfigLexicon::KEY_ESCALATION_WINDOW => ['value' => '5', 'type' => IAppConfig::VALUE_MIXED, 'lazy' => false],
		]);
		$this->appConfig->method('setValueString')->willThrowException(new AppConfigTypeConflictException());
		$this->appConfig->expects(self::once())->method('setValueInt');
		// The removal of the HR group is taken back, the next key still converts
		$this->db->expects(self::once())->method('rollBack');
		$this->db->expects(self::once())->method('commit');

		$this->runStep();
	}
}
