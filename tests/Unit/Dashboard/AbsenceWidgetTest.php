<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Dashboard;

use OCA\Absence\Dashboard\AbsenceWidget;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Service\PermissionService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The wording of the dashboard rows: plural forms and dates follow the language
 * of the person reading them.
 */
class AbsenceWidgetTest extends TestCase {
	private BalanceService&MockObject $balanceService;
	private LeaveRequestMapper&MockObject $requestMapper;
	private AbsenceWidget $widget;

	protected function setUp(): void {
		parent::setUp();
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => $parameters === [] ? $text : vsprintf($text, (array)$parameters),
		);
		$l->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count, array $parameters = []): string
				=> vsprintf($count === 1 ? $singular : $plural, $parameters),
		);
		$l->method('l')->willReturnCallback(
			static fn (string $type, \DateTimeInterface $date, array $options): string
				=> $type . ':' . $options['width'] . ':' . $date->format('j M Y'),
		);

		$clock = $this->createMock(ClockService::class);
		$clock->method('userToday')->willReturn('2026-03-01');
		$this->balanceService = $this->createMock(BalanceService::class);
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);

		$this->widget = new AbsenceWidget(
			$l,
			$this->createMock(IURLGenerator::class),
			$this->createMock(IUserManager::class),
			$this->requestMapper,
			$clock,
			$this->createMock(LeaveTypeMapper::class),
			$this->balanceService,
			$this->createMock(PermissionService::class),
			$this->createMock(ManagerResolver::class),
		);
	}

	public static function remainingDays(): array {
		return [
			'one day' => [1.0, '1 day of annual leave left'],
			'several days' => [12.0, '12 days of annual leave left'],
			'a day and a half' => [1.5, '1.5 days of annual leave left'],
			'nothing' => [0.0, '0 days of annual leave left'],
		];
	}

	#[DataProvider('remainingDays')]
	public function testTheBalanceRowAgreesWithTheNumberOfDays(float $remaining, string $expected): void {
		$this->balanceService->method('getBalance')->willReturn(['balances' => [
			['typeKey' => 'annual', 'remaining' => $remaining, 'pending' => 0.0, 'used' => 3.0],
		]]);
		$this->requestMapper->method('findAllForEmployee')->willReturn([]);

		$items = $this->widget->getItems('itest-casey');

		$this->assertSame($expected, $items[0]->getTitle());
	}

	public function testTheBalanceIsThisYearsAlsoOnceNextYearHasAnEntitlement(): void {
		$this->balanceService->method('currentYear')->willReturn(2026);
		$this->balanceService->method('getBalance')->willReturnCallback(static fn (string $uid, ?int $year = null): array => ['balances' => array_values(array_filter([
			['typeKey' => 'annual', 'year' => 2027, 'remaining' => 30.0, 'pending' => 0.0, 'used' => 0.0],
			['typeKey' => 'annual', 'year' => 2026, 'remaining' => 4.0, 'pending' => 0.0, 'used' => 26.0],
		], static fn (array $row): bool => $year === null || $row['year'] === $year))]);
		$this->requestMapper->method('findAllForEmployee')->willReturn([]);

		$items = $this->widget->getItems('itest-casey');

		$this->assertSame('4 days of annual leave left', $items[0]->getTitle());
	}

	public function testDatesAreWrittenInTheReadersLanguage(): void {
		$this->balanceService->method('getBalance')->willReturn(['balances' => []]);
		$request = new LeaveRequest();
		$request->setEmployeeUid('itest-casey');
		$request->setTypeId(1);
		$request->setStatus(LeaveRequest::STATUS_PENDING);
		$request->setStartDate('2026-03-09');
		$request->setEndDate('2026-03-13');
		$this->requestMapper->method('findAllForEmployee')->willReturn([$request]);

		$items = $this->widget->getItems('itest-casey');

		$this->assertStringContainsString('date:medium:9 Mar 2026 – date:medium:13 Mar 2026', $items[0]->getTitle());
		$this->assertStringNotContainsString('2026-03-09', $items[0]->getTitle());
	}
}
