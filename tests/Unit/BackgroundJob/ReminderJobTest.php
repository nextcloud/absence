<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\BackgroundJob;

use OCA\Absence\BackgroundJob\ReminderJob;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A pending request is reminded once, two working days after it was created
 * (window 3, lead 1), whatever the days the job really runs on (§9).
 * March 2026 starts on a Sunday: the 2nd, 9th, 16th, 23rd and 30th are Mondays.
 */
class ReminderJobTest extends TestCase {
	private ReminderJob $job;
	private \DateTimeImmutable $now;
	private int $storedCutoff = 0;
	/** @var LeaveRequest[] */
	private array $pending = [];
	/** @var int[] ids of the reminded requests, in order */
	private array $reminded = [];

	protected function setUp(): void {
		parent::setUp();
		$clock = $this->createMock(ClockService::class);
		$clock->method('serverNow')->willReturnCallback(fn (): \DateTimeImmutable => $this->now);

		$mapper = $this->createMock(LeaveRequestMapper::class);
		// The half-open window of the real query
		$mapper->method('findPendingCreatedBetween')->willReturnCallback(
			fn (\DateTimeInterface $after, \DateTimeInterface $before): array => array_values(array_filter(
				$this->pending,
				static fn (LeaveRequest $request): bool => $request->getCreatedAt() >= $after && $request->getCreatedAt() < $before,
			)),
		);

		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('notifyReminder')->willReturnCallback(function (LeaveRequest $request): void {
			$this->reminded[] = $request->getId();
		});

		$config = $this->createMock(ConfigService::class);
		$config->method('getEscalationWindowDays')->willReturn(3);
		$config->method('getReminderLeadDays')->willReturn(1);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(fn (): int => $this->storedCutoff);
		$appConfig->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->storedCutoff = $value;
			return true;
		});

		$this->job = new ReminderJob(
			$this->createMock(ITimeFactory::class),
			$clock,
			$mapper,
			$notifications,
			$config,
			$appConfig,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testSecondRunOnTheSameDayRemindsNobodyAgain(): void {
		$this->pendingSince(1, '2026-03-05');

		$this->runOn('2026-03-10');
		$this->runOn('2026-03-10');

		self::assertSame([1], $this->reminded);
	}

	public function testRunAfterMissedDayIncludesIt(): void {
		$this->pendingSince(1, '2026-03-09');
		$this->pendingSince(2, '2026-03-10');
		$this->runOn('2026-03-10');
		$this->reminded = [];

		// Nothing ran on Wednesday the 11th and Thursday the 12th
		$this->runOn('2026-03-13');

		self::assertSame([1, 2], $this->reminded);
	}

	public function testFirstRunTakesOneWorkingDayOnly(): void {
		$this->pendingSince(1, '2026-03-03');
		$this->pendingSince(2, '2026-03-04');

		$this->runOn('2026-03-09');

		self::assertSame([2], $this->reminded);
	}

	public function testWeekendRunsSendNothingAndMondayContinues(): void {
		$this->pendingSince(1, '2026-03-10');
		$this->pendingSince(2, '2026-03-11');
		$this->runOn('2026-03-13');
		self::assertSame([1], $this->reminded);

		$this->runOn('2026-03-14');
		$this->runOn('2026-03-15');
		self::assertSame([1], $this->reminded);

		$this->runOn('2026-03-16');
		$this->runOn('2026-03-17');
		self::assertSame([1, 2], $this->reminded);
	}

	public function testRequestCreatedOnWeekendIsRemindedOnce(): void {
		$this->pendingSince(1, '2026-03-14');

		foreach (['2026-03-16', '2026-03-17', '2026-03-18', '2026-03-19'] as $day) {
			$this->runOn($day);
		}

		self::assertSame([1], $this->reminded);
	}

	public function testCatchUpStopsAfterSevenDays(): void {
		$this->runOn('2026-03-04');
		$this->pendingSince(1, '2026-03-10');
		$this->pendingSince(2, '2026-03-22');
		$this->pendingSince(3, '2026-03-23');
		$this->pendingSince(4, '2026-03-27');

		// Four weeks without a run: the cut-off is Monday the 30th
		$this->runOn('2026-04-01');

		self::assertSame([3, 4], $this->reminded);
	}

	private function pendingSince(int $id, string $day): void {
		$request = new LeaveRequest();
		$request->setId($id);
		$request->setManagerUid('itest-manager');
		$request->setStatus(LeaveRequest::STATUS_PENDING);
		$request->setCreatedAt(new \DateTime($day . ' 10:00:00', new \DateTimeZone('UTC')));
		$this->pending[] = $request;
	}

	private function runOn(string $day): void {
		$this->now = new \DateTimeImmutable($day . ' 03:00:00', new \DateTimeZone('UTC'));
		(new \ReflectionMethod($this->job, 'run'))->invoke($this->job, null);
	}
}
