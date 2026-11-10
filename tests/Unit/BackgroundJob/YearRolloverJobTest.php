<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\BackgroundJob;

use OCA\Absence\BackgroundJob\YearRolloverJob;
use OCA\Absence\ConfigLexicon;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EntitlementService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class YearRolloverJobTest extends TestCase {
	private EntitlementService&MockObject $entitlementService;
	private IAppConfig&MockObject $appConfig;
	private YearRolloverJob $job;

	protected function setUp(): void {
		parent::setUp();
		$this->entitlementService = $this->createMock(EntitlementService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueInt')
			->with(ConfigService::APP_ID, ConfigLexicon::KEY_LAST_ROLLOVER_YEAR)
			->willReturn(2026);
		$clock = $this->createMock(ClockService::class);
		$clock->method('serverYear')->willReturn(2027);
		$this->job = new YearRolloverJob($this->createMock(ITimeFactory::class), $this->entitlementService, $clock, $this->appConfig);
	}

	private function runJob(): void {
		(new \ReflectionMethod($this->job, 'run'))->invoke($this->job, null);
	}

	public function testACleanRolloverRecordsTheYear(): void {
		$this->entitlementService->expects($this->once())->method('rollover')->with(2026)
			->willReturn(['affected' => 3, 'skipped' => 1, 'failed' => 0]);
		$this->appConfig->expects($this->once())->method('setValueInt')
			->with(ConfigService::APP_ID, ConfigLexicon::KEY_LAST_ROLLOVER_YEAR, 2027);

		$this->runJob();
	}

	public function testARolloverWithFailedRowsIsTriedAgain(): void {
		$this->entitlementService->method('rollover')->with(2026)
			->willReturn(['affected' => 2, 'skipped' => 0, 'failed' => 1]);
		$this->appConfig->expects($this->never())->method('setValueInt');
		// the expiry pass does not depend on the rollover
		$this->entitlementService->expects($this->once())->method('expireCarryOver')->with(2027);

		$this->runJob();
	}
}
