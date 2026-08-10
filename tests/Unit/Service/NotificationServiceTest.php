<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Service\NotificationService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The comments people leave on a request are the substance of the workflow — a
 * rejection reason, a manager's question, HR's answer. These tests pin down that
 * they actually travel out with the notification and the email instead of only
 * living behind the request's Comments tab.
 */
class NotificationServiceTest extends TestCase {
	private INotificationManager&MockObject $notificationManager;
	private IMailer&MockObject $mailer;
	private IUserManager&MockObject $userManager;
	private IEMailTemplate&MockObject $template;
	private NotificationService $service;

	/** Subject parameters of every notification the service pushed. */
	private array $pushed = [];
	/** Quoted note blocks added to the email, as [text, metaInfo] pairs. */
	private array $emailNotes = [];
	/** Paragraphs added to the email body. */
	private array $emailBody = [];

	protected function setUp(): void {
		parent::setUp();
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$l10nFactory = $this->createMock(IFactory::class);
		$logger = $this->createMock(LoggerInterface::class);

		$notification = $this->createMock(INotification::class);
		$notification->method(self::anything())->willReturnSelf();
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters) use ($notification): INotification {
				$this->pushed[] = $parameters;
				return $notification;
			},
		);
		$this->notificationManager->method('createNotification')->willReturn($notification);

		$l10n = $this->createMock(IL10N::class);
		// Interpolate for real, so a mismatch between a format string and its
		// arguments shows up here rather than in a recipient's inbox.
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => $parameters === [] ? $text : vsprintf($text, (array)$parameters),
		);
		$l10nFactory->method('getUserLanguage')->willReturn('en');
		$l10nFactory->method('get')->willReturn($l10n);

		$this->template = $this->createMock(IEMailTemplate::class);
		$this->template->method('addBodyText')->willReturnCallback(
			function (string $text): void {
				$this->emailBody[] = $text;
			},
		);
		$this->template->method('addBodyListItem')->willReturnCallback(
			function (string $text, string $metaInfo = ''): void {
				$this->emailNotes[] = [$text, $metaInfo];
			},
		);
		$this->mailer->method('createEMailTemplate')->willReturn($this->template);
		$this->mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));

		$this->service = new NotificationService(
			$this->notificationManager,
			$this->mailer,
			$this->userManager,
			$urlGenerator,
			$l10nFactory,
			$logger,
		);
	}

	/** Everyone named here exists, has mail configured and has a display name. */
	private function withUsers(string ...$uids): void {
		$this->userManager->method('get')->willReturnCallback(
			function (string $uid) use ($uids): ?IUser {
				if (!in_array($uid, $uids, true)) {
					return null;
				}
				$user = $this->createMock(IUser::class);
				$user->method('getEMailAddress')->willReturn($uid . '@example.com');
				$user->method('getDisplayName')->willReturn(ucfirst($uid));
				return $user;
			},
		);
	}

	private function request(): LeaveRequest {
		$request = new LeaveRequest();
		$request->setId(7);
		$request->setEmployeeUid('emp');
		$request->setManagerUid('boss');
		$request->setStartDate('2026-02-10');
		$request->setEndDate('2026-02-12');
		$request->setStatus(LeaveRequest::STATUS_PENDING);
		return $request;
	}

	public function testACommentReachesTheOthersWithItsTextAndAuthor(): void {
		$this->withUsers('emp', 'boss');

		$this->service->notifyComment($this->request(), 'boss', 'Can you move this a day later?', ['emp', 'boss']);

		// One notification, to the employee: the author does not need telling.
		self::assertCount(1, $this->pushed);
		self::assertSame('Can you move this a day later?', $this->pushed[0]['note']);
		self::assertSame('boss', $this->pushed[0]['noteAuthor']);
		self::assertSame([['Can you move this a day later?', 'Boss wrote:']], $this->emailNotes);
	}

	public function testALongCommentIsShortenedForTheNotificationButNotForTheEmail(): void {
		// A notification renders on one line; the email has room for the whole thing.
		$this->withUsers('emp', 'boss');
		$body = str_repeat('a', 500);

		$this->service->notifyComment($this->request(), 'boss', $body, ['emp']);

		self::assertSame(str_repeat('a', 199) . '…', $this->pushed[0]['note']);
		self::assertSame($body, $this->emailNotes[0][0]);
	}

	public function testAMultilineCommentIsFlattenedForTheNotificationOnly(): void {
		$this->withUsers('emp', 'boss');

		$this->service->notifyComment($this->request(), 'boss', "First line.\n\nSecond line.", ['emp']);

		self::assertSame('First line. Second line.', $this->pushed[0]['note']);
		self::assertSame("First line.\n\nSecond line.", $this->emailNotes[0][0]);
	}

	public function testTheApplicantsReasonTravelsWithTheNewRequestNotification(): void {
		// Without it the manager is asked to decide on a request whose stated reason
		// they can only read by opening the app.
		$this->withUsers('emp', 'boss');
		$request = $this->request();
		$request->setReason('Family wedding abroad.');

		$this->service->notifyNewRequest($request, 'boss');

		self::assertSame('Family wedding abroad.', $this->pushed[0]['note']);
		self::assertSame('emp', $this->pushed[0]['noteAuthor']);
		self::assertSame([['Family wedding abroad.', 'Emp wrote:']], $this->emailNotes);
	}

	public function testTheDecisionCommentTravelsWithTheOutcome(): void {
		$this->withUsers('emp', 'boss');
		$request = $this->request();
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		$request->setDecidedBy('boss');
		$request->setDecisionComment('Approved, but please brief Sam first.');

		$this->service->notifyDecision($request, true);

		self::assertSame('Approved, but please brief Sam first.', $this->pushed[0]['note']);
		self::assertSame('boss', $this->pushed[0]['noteAuthor']);
		self::assertSame([['Approved, but please brief Sam first.', 'Boss wrote:']], $this->emailNotes);
	}

	public function testARejectionEmailStatesTheReasonOnceOnly(): void {
		// The reason used to be pasted into the summary sentence; it is now quoted
		// below it, and quoting it twice would read as a mistake.
		$this->withUsers('emp', 'boss');
		$request = $this->request();
		$request->setStatus(LeaveRequest::STATUS_REJECTED);
		$request->setDecidedBy('boss');
		$request->setDecisionComment('Too many people out that week.');

		$this->service->notifyDecision($request, false);

		self::assertSame(['Your leave request for 2026-02-10 – 2026-02-12 was declined.'], $this->emailBody);
		self::assertSame([['Too many people out that week.', 'Boss wrote:']], $this->emailNotes);
	}

	public function testADeclinedWithdrawalCarriesTheReasonItRecordedAsAComment(): void {
		$this->withUsers('emp', 'boss');

		$this->service->notifyWithdrawalRejected($this->request(), 'We have nobody to cover.', 'boss');

		self::assertSame('We have nobody to cover.', $this->pushed[0]['note']);
		self::assertSame([['We have nobody to cover.', 'Boss wrote:']], $this->emailNotes);
	}

	public function testAMessageWithNothingWrittenOnItQuotesNothing(): void {
		$this->withUsers('emp', 'boss');
		$request = $this->request();
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		$request->setDecidedBy('boss');
		$request->setDecisionComment(null);

		$this->service->notifyDecision($request, true);

		self::assertSame('', $this->pushed[0]['note']);
		self::assertSame([], $this->emailNotes);
	}

	public function testTheReplacementIsNotToldWhyTheEmployeeIsAway(): void {
		// Cover duty does not come with a right to read the reason, which can be
		// medical. The replacement gets the dates and nothing else.
		$this->withUsers('emp', 'stand-in');
		$request = $this->request();
		$request->setReplacementUid('stand-in');
		$request->setReason('Surgery.');

		$this->service->notifyReplacementAssigned($request);

		self::assertSame('', $this->pushed[0]['note']);
		self::assertSame([], $this->emailNotes);
	}
}
