<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Migration;

use OCA\Absence\Migration\RemoveAuditLogCondition;
use OCP\HintException;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RemoveAuditLogConditionTest extends TestCase {
	private IConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function repair(mixed $condition): void {
		$this->config->method('getSystemValue')->with('log.condition', [])->willReturn($condition);
		(new RemoveAuditLogCondition($this->config, $this->logger))->run($this->createMock(IOutput::class));
	}

	public function testTakesTheAppOutAndKeepsTheRestOfTheCondition(): void {
		$this->config->expects(self::once())->method('setSystemValue')
			->with('log.condition', ['users' => ['itest-admin'], 'apps' => ['files']]);
		$this->config->expects(self::never())->method('deleteSystemValue');

		$this->repair(['users' => ['itest-admin'], 'apps' => ['absence', 'files']]);
	}

	public function testRemovesAConditionThatOnlyHeldTheApp(): void {
		$this->config->expects(self::once())->method('deleteSystemValue')->with('log.condition');
		$this->config->expects(self::never())->method('setSystemValue');

		$this->repair(['apps' => ['absence']]);
	}

	public static function conditionsWithoutTheApp(): array {
		return [
			'no condition' => [[]],
			'other apps' => [['apps' => ['files']]],
			'no apps' => [['users' => ['itest-admin']]],
			'not a list' => [['apps' => 'absence']],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('conditionsWithoutTheApp')]
	public function testWritesNothingWhenTheAppIsNotInTheCondition(array $condition): void {
		$this->config->expects(self::never())->method('setSystemValue');
		$this->config->expects(self::never())->method('deleteSystemValue');

		$this->repair($condition);
	}

	public function testAReadOnlyConfigIsLoggedAndDoesNotStopTheUpgrade(): void {
		$this->config->method('deleteSystemValue')->willThrowException(new HintException('Config is set to be read-only'));
		$this->logger->expects(self::once())->method('warning');

		$this->repair(['apps' => ['absence']]);
	}
}
