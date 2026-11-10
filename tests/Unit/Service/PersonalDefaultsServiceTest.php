<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeService;
use OCA\Absence\Service\PersonalDefaultsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class PersonalDefaultsServiceTest extends TestCase {
	private ContainerInterface&MockObject $container;
	private LoggerInterface&MockObject $logger;
	private PersonalDefaultsService $service;

	protected function setUp(): void {
		parent::setUp();
		$config = $this->createMock(ConfigService::class);
		$config->method('getPersonalConfig')->willReturn([
			ConfigLexicon::KEY_HOLIDAY_COUNTRY => 'ZZ',
			ConfigLexicon::KEY_HOLIDAY_REGION => 'ZZ-T1',
		]);
		$clock = $this->createMock(ClockService::class);
		$clock->method('userToday')->willReturn('2026-03-02');
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// No contract: the weekdays have to come from Availability or the default.
		$this->service = new PersonalDefaultsService(
			$config,
			$this->createMock(EmployeeService::class),
			$clock,
			$this->container,
			$this->logger,
		);
	}

	public function testFallsBackToTheDefaultWeekWhenTheDavMapperCannotBeResolved(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('not resolvable'));
		$this->logger->expects(self::once())->method('warning');

		self::assertSame(
			['workWeekdays' => ConfigService::DEFAULT_WORK_WEEKDAYS, 'holidayCountry' => 'ZZ', 'holidayRegion' => 'ZZ-T1'],
			$this->service->resolve('itest-emp'),
		);
	}
}
