<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Notification;

use OCA\Absence\Notification\Notifier;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\NotificationService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * What a recipient actually reads in the notification area. The subject line is the
 * only part guaranteed to be seen, so these pin down what lands there.
 */
class NotifierTest extends TestCase {
	private Notifier $notifier;
	private string $parsedSubject = '';
	private string $parsedMessage = '';

	protected function setUp(): void {
		parent::setUp();
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
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid === '') {
					return null;
				}
				$user = $this->createMock(IUser::class);
				$user->method('getDisplayName')->willReturn(ucfirst($uid));
				return $user;
			},
		);

		$this->notifier = new Notifier($l10nFactory, $this->createMock(IURLGenerator::class), $userManager);
	}

	/** @param array<string,mixed> $parameters */
	private function prepare(string $subject, array $parameters): void {
		// The action is built with a fluent chain, so its setters have to return it.
		$action = $this->createMock(IAction::class);
		$action->method(self::anything())->willReturnSelf();

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(ConfigService::APP_ID);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$notification->method('getObjectId')->willReturn('7');
		$notification->method('createAction')->willReturn($action);
		$notification->method('setParsedSubject')->willReturnCallback(
			function (string $text) use ($notification): INotification {
				$this->parsedSubject = $text;
				return $notification;
			},
		);
		$notification->method('setParsedMessage')->willReturnCallback(
			function (string $text) use ($notification): INotification {
				$this->parsedMessage = $text;
				return $notification;
			},
		);

		$this->notifier->prepare($notification, 'en');
	}

	public function testShortNoticeSaysSoInTheSubjectLine(): void {
		// The subject is the one line that is always read, and a manager scanning the
		// notification area has to be able to tell this one apart from the rest.
		$this->prepare(NotificationService::SUBJECT_NEW_REQUEST, [
			'employee' => 'emp',
			'requestId' => '7',
			'noticeDays' => 5,
			'noticePeriod' => 14,
		]);

		self::assertSame('Short notice: leave request from Emp', $this->parsedSubject);
		self::assertSame('The leave starts in 5 days, less than the 14 days of notice expected.', $this->parsedMessage);
	}

	public function testAmpleNoticeReadsAsAnOrdinaryRequest(): void {
		$this->prepare(NotificationService::SUBJECT_NEW_REQUEST, [
			'employee' => 'emp',
			'requestId' => '7',
			'noticeDays' => null,
			'noticePeriod' => null,
		]);

		self::assertSame('New leave request from Emp', $this->parsedSubject);
		self::assertSame('Review it in Absence.', $this->parsedMessage);
	}

	public function testTheWarningLeadsAndTheReasonFollows(): void {
		// Both matter and neither may swallow the other: the warning bears on the
		// answer, the reason on the ask.
		$this->prepare(NotificationService::SUBJECT_NEW_REQUEST, [
			'employee' => 'emp',
			'requestId' => '7',
			'note' => 'Family wedding abroad.',
			'noteAuthor' => 'emp',
			'noticeDays' => 1,
			'noticePeriod' => 14,
		]);

		self::assertSame(
			'The leave starts in 1 day, less than the 14 days of notice expected. Family wedding abroad.',
			$this->parsedMessage,
		);
	}

	public function testANotificationStoredBeforeThisFeatureStillParses(): void {
		// Rows already in the database carry none of the new parameters, and a notifier
		// that threw on them would break every pending notification on upgrade.
		$this->prepare(NotificationService::SUBJECT_NEW_REQUEST, ['employee' => 'emp']);

		self::assertSame('New leave request from Emp', $this->parsedSubject);
		self::assertSame('Review it in Absence.', $this->parsedMessage);
	}

	public function testTheEscalationAndReminderCarryTheWarningToo(): void {
		// HR hears about a request through the escalation, and the reminder fires days
		// later still — by which point the notice given is shorter, not longer.
		$this->prepare(NotificationService::SUBJECT_ESCALATION, [
			'employee' => 'emp', 'noticeDays' => 2, 'noticePeriod' => 14,
		]);
		self::assertSame('Short notice: leave request from Emp needs HR', $this->parsedSubject);

		$this->prepare(NotificationService::SUBJECT_REMINDER, [
			'employee' => 'emp', 'noticeDays' => 0, 'noticePeriod' => 14,
		]);
		self::assertSame('Short notice: Emp is still waiting for a decision', $this->parsedSubject);
		self::assertSame('The leave starts today, with none of the 14 days of notice expected.', $this->parsedMessage);
	}
}
