<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\NoticeService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NoticeServiceTest extends TestCase {
	private ConfigService&MockObject $config;
	private ClockService&MockObject $clock;
	private NoticeService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(ConfigService::class);
		$this->clock = $this->createMock(ClockService::class);
		// A fixed "today" for every case below, so the day arithmetic is readable.
		$this->clock->method('serverToday')->willReturn('2026-03-01');
		$this->service = new NoticeService($this->config, $this->clock);
	}

	private function request(string $startDate, string $status = LeaveRequest::STATUS_PENDING): LeaveRequest {
		$request = new LeaveRequest();
		$request->setId(7);
		$request->setEmployeeUid('emp');
		$request->setStartDate($startDate);
		$request->setEndDate($startDate);
		$request->setStatus($status);
		return $request;
	}

	public function testCountsCalendarDaysToTheFirstDayOfLeave(): void {
		// Calendar days, not working days: "two weeks' notice" is a fortnight on the
		// wall calendar, and 15 March is 14 days after 1 March whatever falls between.
		self::assertSame(14, $this->service->daysUntilStart($this->request('2026-03-15')));
		self::assertSame(0, $this->service->daysUntilStart($this->request('2026-03-01')));
	}

	public function testCountsBackwardsForLeaveThatHasStarted(): void {
		// An edit or a late correction can move a pending request into the past.
		self::assertSame(-3, $this->service->daysUntilStart($this->request('2026-02-26')));
	}

	public function testWarnsWhenTheLeaveStartsInsideTheNoticePeriod(): void {
		$this->config->method('getNoticePeriodDays')->willReturn(14);

		self::assertSame(
			['days' => 5, 'noticePeriod' => 14],
			$this->service->warningFor($this->request('2026-03-06')),
		);
	}

	public function testIsSilentOnceTheNoticePeriodIsMet(): void {
		// Exactly the notice period is notice enough — the threshold is "less than".
		$this->config->method('getNoticePeriodDays')->willReturn(14);

		self::assertNull($this->service->warningFor($this->request('2026-03-15')));
		self::assertNull($this->service->warningFor($this->request('2026-06-01')));
	}

	public function testZeroDaysSwitchesTheWarningOff(): void {
		// The admin's off switch: not "warn about everything", which is what a plain
		// "starts in fewer than 0 days" comparison would do.
		$this->config->method('getNoticePeriodDays')->willReturn(0);

		self::assertNull($this->service->warningFor($this->request('2026-03-01')));
	}

	public function testWarnsOnAnEscalatedRequestBecauseHrStillHasToDecide(): void {
		$this->config->method('getNoticePeriodDays')->willReturn(14);

		self::assertNotNull($this->service->warningFor($this->request('2026-03-06', LeaveRequest::STATUS_ESCALATED)));
	}

	/**
	 * @return iterable<string,array{string}>
	 */
	public static function decidedStatuses(): iterable {
		yield 'approved' => [LeaveRequest::STATUS_APPROVED];
		yield 'rejected' => [LeaveRequest::STATUS_REJECTED];
		yield 'cancelled' => [LeaveRequest::STATUS_CANCELLED];
	}

	/**
	 * @dataProvider decidedStatuses
	 */
	public function testSaysNothingOnceTheDecisionIsMade(string $status): void {
		// Short notice is an input to a decision. Afterwards it is only a reproach on
		// the record — and it would also flag sick leave and other auto-approved types
		// (§4.1), which reach APPROVED without ever being pending and which nobody
		// could have given notice of.
		$this->config->method('getNoticePeriodDays')->willReturn(14);

		self::assertNull($this->service->warningFor($this->request('2026-03-02', $status)));
	}

	public function testTheSentenceCountsTheDaysAndNamesTheExpectation(): void {
		$l = $this->l10n();

		self::assertSame(
			'The leave starts in 5 days, less than the 14 days of notice expected.',
			NoticeService::sentence($l, ['days' => 5, 'noticePeriod' => 14]),
		);
		// Singular, rather than "in 1 days".
		self::assertSame(
			'The leave starts in 1 day, less than the 14 days of notice expected.',
			NoticeService::sentence($l, ['days' => 1, 'noticePeriod' => 14]),
		);
	}

	public function testTheSentenceDoesNotClaimLeaveStartsInZeroDays(): void {
		$l = $this->l10n();

		self::assertSame(
			'The leave starts today, with none of the 14 days of notice expected.',
			NoticeService::sentence($l, ['days' => 0, 'noticePeriod' => 14]),
		);
		self::assertSame(
			'The leave has already started, though 14 days of notice are expected.',
			NoticeService::sentence($l, ['days' => -3, 'noticePeriod' => 14]),
		);
	}

	/** Interpolates for real, so a format-string mismatch fails here. */
	private function l10n(): IL10N&MockObject {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => $parameters === [] ? $text : vsprintf($text, (array)$parameters),
		);
		$l->method('n')->willReturnCallback(
			static function (string $singular, string $plural, int $count, array $parameters = []): string {
				$text = str_replace('%n', (string)$count, $count === 1 ? $singular : $plural);
				return $parameters === [] ? $text : vsprintf($text, $parameters);
			},
		);
		return $l;
	}
}
