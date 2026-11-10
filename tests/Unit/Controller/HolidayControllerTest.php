<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Controller;

use OCA\Absence\Controller\HolidayController;
use OCA\Absence\Service\HolidayService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Tests\Unit\L10nMockTrait;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HolidayControllerTest extends TestCase {
	use L10nMockTrait;

	private HolidayService&MockObject $service;
	private HolidayController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(HolidayService::class);
		$this->controller = new HolidayController(
			'absence',
			$this->createMock(IRequest::class),
			'itest-employee',
			$this->service,
			$this->createMock(PermissionService::class),
			$this->l10nMock(),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function rejectedRanges(): array {
		return [
			'longer than three years' => ['2026-01-01', '2029-01-02'],
			'not a date' => ['0001-01-01', 'someday'],
			'end before start' => ['2027-02-01', '2027-01-01'],
		];
	}

	#[DataProvider('rejectedRanges')]
	public function testApplyingRejectsTheRange(string $from, string $to): void {
		$this->service->expects(self::never())->method('forRegions');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller->applying('ZZ', $from, $to)->getStatus());
	}

	public function testApplyingAcceptsTheThreeYearsTheRequestDialogLoads(): void {
		// 2027 to 2029 holds a leap year, so this is the longest window the dialog asks for.
		$this->service->expects(self::once())->method('forRegions')->with(['ZZ'], '2027-01-01', '2029-12-31')->willReturn([]);
		self::assertSame(Http::STATUS_OK, $this->controller->applying('ZZ', '2027-01-01', '2029-12-31')->getStatus());
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function rejectedYears(): array {
		return ['before 2000' => [1999], 'after 2100' => [2101], 'negative' => [-5]];
	}

	#[DataProvider('rejectedYears')]
	public function testIndexRejectsTheYear(int $year): void {
		$this->service->expects(self::never())->method('listForYear');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller->index($year)->getStatus());
	}

	public function testIndexAcceptsTheBoundaryYears(): void {
		$this->service->expects(self::exactly(2))->method('listForYear')->willReturn([]);
		self::assertSame(Http::STATUS_OK, $this->controller->index(2000)->getStatus());
		self::assertSame(Http::STATUS_OK, $this->controller->index(2100)->getStatus());
	}
}
