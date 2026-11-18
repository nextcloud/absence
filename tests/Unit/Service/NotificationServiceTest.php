<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\CommentMailMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\RequestComment;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\NoticeService;
use OCA\Absence\Service\NotificationService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Tests\Unit\TraceArgumentsTrait;
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
use PHPUnit\Framework\Attributes\DataProvider;
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
	use TraceArgumentsTrait;

	private INotificationManager&MockObject $notificationManager;
	private IMailer&MockObject $mailer;
	private IUserManager&MockObject $userManager;
	private IEMailTemplate&MockObject $template;
	private NoticeService&MockObject $notice;
	private LoggerInterface&MockObject $logger;
	private NotificationService $service;

	/** Subject parameters of every notification the service pushed. */
	private array $pushed = [];
	/** Recipient of every notification the service built. */
	private array $recipients = [];
	/** Quoted note blocks added to the email, as [text, metaInfo] pairs. */
	private array $emailNotes = [];
	/** Paragraphs added to the email body. */
	private array $emailBody = [];
	/** The email's subject line. */
	private string $emailSubject = '';
	/** Recipients whose comment email was queued. */
	private array $queued = [];
	/** Whether the request is of a confidential type (§5.7). */
	private bool $confidential = false;
	/** Links of the buttons added to the email. */
	private array $emailButtons = [];
	/** The setting that lets emails quote what people wrote. */
	private bool $quoteText = true;

	protected function setUp(): void {
		parent::setUp();
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example.com/apps/absence/');
		$l10nFactory = $this->createMock(IFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$notification = $this->createMock(INotification::class);
		$notification->method(self::anything())->willReturnSelf();
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters) use ($notification): INotification {
				$this->pushed[] = $parameters;
				return $notification;
			},
		);
		$notification->method('setUser')->willReturnCallback(
			function (string $uid) use ($notification): INotification {
				$this->recipients[] = $uid;
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
		// Mirrors L10N::n: pick the form by count, substitute %n, then vsprintf the rest.
		$l10n->method('n')->willReturnCallback(
			static function (string $singular, string $plural, int $count, array $parameters = []): string {
				$text = str_replace('%n', (string)$count, $count === 1 ? $singular : $plural);
				return $parameters === [] ? $text : vsprintf($text, $parameters);
			},
		);
		$l10nFactory->method('getUserLanguage')->willReturn('en');
		$l10nFactory->method('get')->willReturn($l10n);

		$this->template = $this->createMock(IEMailTemplate::class);
		$this->template->method('setSubject')->willReturnCallback(
			function (string $subject): void {
				$this->emailSubject = $subject;
			},
		);
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
		$this->template->method('addBodyButton')->willReturnCallback(
			function (string $text, string $url): void {
				$this->emailButtons[] = $url;
			},
		);
		$this->mailer->method('createEMailTemplate')->willReturn($this->template);
		$this->mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));

		// Notice sufficient unless a test says otherwise. The wording itself comes from
		// the real static NoticeService::sentence(), so these tests check the message a
		// recipient actually gets rather than a stub of it.
		$this->notice = $this->createMock(NoticeService::class);

		$config = $this->createMock(ConfigService::class);
		$config->method('isMailQuoteTextEnabled')->willReturnCallback(fn (): bool => $this->quoteText);

		$commentMails = $this->createMock(CommentMailMapper::class);
		$commentMails->method('queue')->willReturnCallback(
			function (RequestComment $comment, array $recipientUids): void {
				$this->queued = [...$this->queued, ...$recipientUids];
			},
		);

		// HR are the uids starting with `hr`.
		$permission = $this->createMock(PermissionService::class);
		$permission->method('isHrOnlyRequest')->willReturnCallback(fn (): bool => $this->confidential);
		$permission->method('isHr')->willReturnCallback(static fn (string $uid): bool => str_starts_with($uid, 'hr'));

		$this->service = new NotificationService(
			$this->notificationManager,
			$this->mailer,
			$this->userManager,
			$urlGenerator,
			$l10nFactory,
			$this->notice,
			$this->logger,
			$commentMails,
			$permission,
			$config,
		);
	}

	/**
	 * Everyone named here exists, has mail configured and has a display name.
	 * A uid ending in `-disabled` is a disabled account.
	 */
	private function withUsers(string ...$uids): void {
		$this->userManager->method('get')->willReturnCallback(
			function (string $uid) use ($uids): ?IUser {
				if (!in_array($uid, $uids, true)) {
					return null;
				}
				$user = $this->createMock(IUser::class);
				$user->method('isEnabled')->willReturn(!str_ends_with($uid, '-disabled'));
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

	private function comment(string $authorUid, string $body): RequestComment {
		$comment = new RequestComment();
		$comment->setId(3);
		$comment->setRequestId(7);
		$comment->setAuthorUid($authorUid);
		$comment->setBody($body);
		return $comment;
	}

	public function testACommentIsPushedToTheOthersWithItsTextAndAuthorAndMailedLater(): void {
		$this->withUsers('emp', 'boss', 'hr-disabled');

		$this->service->notifyComment($this->request(), $this->comment('boss', 'Can you move this a day later?'), ['emp', 'boss', 'hr-disabled']);

		// One notification, to the employee: the author does not need telling.
		self::assertCount(1, $this->pushed);
		self::assertSame('Can you move this a day later?', $this->pushed[0]['note']);
		self::assertSame('boss', $this->pushed[0]['noteAuthor']);
		self::assertSame([], $this->emailNotes);
		self::assertSame(['emp'], $this->queued);
	}

	public function testTheCommentMailNamesADeletedAuthorAsSuch(): void {
		$this->withUsers('emp');

		$this->service->sendCommentMail($this->request(), 'emp', [$this->comment('gone', 'Can you move this a day later?')]);

		self::assertSame('New comment from Deleted account', $this->emailSubject);
		self::assertSame([['Can you move this a day later?', 'Deleted account wrote:']], $this->emailNotes);
	}

	public function testTheCommentMailQuotesEveryCommentWithItsAuthor(): void {
		$this->withUsers('emp', 'boss', 'hr');

		$this->service->sendCommentMail($this->request(), 'emp', [
			$this->comment('boss', 'Can you move this a day later?'),
			$this->comment('hr', 'Or split it in two.'),
		]);

		self::assertSame('New comments on the leave request of Emp', $this->emailSubject);
		self::assertSame([['Can you move this a day later?', 'Boss wrote:'], ['Or split it in two.', 'Hr wrote:']], $this->emailNotes);
	}

	public function testALongCommentIsShortenedForTheNotificationButNotForTheEmail(): void {
		// A notification renders on one line; the email has room for the whole thing.
		$this->withUsers('emp', 'boss');
		$comment = $this->comment('boss', str_repeat('a', 500));

		$this->service->notifyComment($this->request(), $comment, ['emp']);
		$this->service->sendCommentMail($this->request(), 'emp', [$comment]);

		self::assertSame(str_repeat('a', 199) . '…', $this->pushed[0]['note']);
		self::assertSame(str_repeat('a', 500), $this->emailNotes[0][0]);
		self::assertSame('New comment from Boss', $this->emailSubject);
	}

	public function testAMultilineCommentIsFlattenedForTheNotificationOnly(): void {
		$this->withUsers('emp', 'boss');
		$comment = $this->comment('boss', "First line.\n\nSecond line.");

		$this->service->notifyComment($this->request(), $comment, ['emp']);
		$this->service->sendCommentMail($this->request(), 'emp', [$comment]);

		self::assertSame('First line. Second line.', $this->pushed[0]['note']);
		self::assertSame("First line.\n\nSecond line.", $this->emailNotes[0][0]);
	}

	public function testWithoutQuotingTheCommentMailCountsTheCommentsAndLinksToThem(): void {
		$this->quoteText = false;
		$this->withUsers('emp', 'boss', 'hr');

		$this->service->sendCommentMail($this->request(), 'emp', [
			$this->comment('boss', 'Can you move this a day later?'),
			$this->comment('hr', 'Or split it in two.'),
		]);

		self::assertSame('New comments on the leave request of Emp', $this->emailSubject);
		self::assertSame(['There are 2 new comments on the leave request of Emp for 2026-02-10 – 2026-02-12.'], $this->emailBody);
		self::assertSame([], $this->emailNotes);
		self::assertSame(['https://cloud.example.com/apps/absence/#/requests/7'], $this->emailButtons);
	}

	public function testWithoutQuotingASingleCommentMailSaysThereIsOne(): void {
		$this->quoteText = false;
		$this->withUsers('emp', 'boss');

		$this->service->sendCommentMail($this->request(), 'emp', [$this->comment('boss', 'Can you move this a day later?')]);

		self::assertSame('New comment from Boss', $this->emailSubject);
		self::assertSame(['There is a new comment on the leave request of Emp for 2026-02-10 – 2026-02-12.'], $this->emailBody);
		self::assertSame([], $this->emailNotes);
	}

	public function testWithoutQuotingTheNotificationKeepsItsPreview(): void {
		$this->quoteText = false;
		$this->withUsers('emp', 'boss');
		$comment = $this->comment('boss', str_repeat('a', 500));

		$this->service->notifyComment($this->request(), $comment, ['emp']);

		self::assertSame(str_repeat('a', 199) . '…', $this->pushed[0]['note']);
		self::assertSame('boss', $this->pushed[0]['noteAuthor']);
	}

	public function testADecisionNotificationRemembersTheVersionItAsksAbout(): void {
		$this->withUsers('emp', 'boss');
		$request = $this->request();
		$request->setUpdatedAt(new \DateTime('2026-02-01T09:00:00+00:00'));

		$this->service->notifyNewRequest($request, 'boss');

		self::assertSame('2026-02-01T09:00:00+00:00', $this->pushed[0]['updatedAt']);
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

	public function testADisabledAccountIsNeitherNotifiedNorMailed(): void {
		$this->withUsers('emp', 'boss-disabled');
		$this->mailer->expects(self::never())->method('send');

		$this->service->notifyNewRequest($this->request(), 'boss-disabled');

		self::assertSame([], $this->pushed);
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

	public function testWithoutQuotingADeclineMailKeepsItsSummaryButNotTheReason(): void {
		$this->quoteText = false;
		$this->withUsers('emp', 'boss');
		$request = $this->request();
		$request->setStatus(LeaveRequest::STATUS_REJECTED);
		$request->setDecidedBy('boss');
		$request->setDecisionComment('Too many people out that week.');

		$this->service->notifyDecision($request, false);

		self::assertSame('Your leave was declined', $this->emailSubject);
		self::assertSame(['Your leave request for 2026-02-10 – 2026-02-12 was declined.'], $this->emailBody);
		self::assertSame([], $this->emailNotes);
		self::assertSame(['https://cloud.example.com/apps/absence/#/requests/7'], $this->emailButtons);
		self::assertSame('Too many people out that week.', $this->pushed[0]['note']);
	}

	public function testWithoutQuotingTheShortNoticeWarningStaysButTheReasonGoes(): void {
		$this->quoteText = false;
		$this->withUsers('emp', 'boss');
		$request = $this->request();
		$request->setReason('Family wedding abroad.');
		$this->notice->method('warningFor')->willReturn(['days' => 5, 'noticePeriod' => 14]);

		$this->service->notifyNewRequest($request, 'boss');

		self::assertSame([
			'Emp requested leave for 2026-02-10 – 2026-02-12. Please review it in Absence.',
			'The leave starts in 5 days, less than the 14 days of notice expected.',
		], $this->emailBody);
		self::assertSame([], $this->emailNotes);
		self::assertSame('Family wedding abroad.', $this->pushed[0]['note']);
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

	public function testShortNoticeIsInTheMailSubjectAndTheNotification(): void {
		// The heading doubles as the mail's subject line, so short notice is visible in
		// the inbox — while the decider still has time to do something about it.
		$this->withUsers('emp', 'boss');
		$request = $this->request();
		$request->setReason('Family wedding abroad.');
		$this->notice->method('warningFor')->willReturn(['days' => 5, 'noticePeriod' => 14]);

		$this->service->notifyNewRequest($request, 'boss');

		self::assertSame('Short notice: leave request from Emp', $this->emailSubject);
		self::assertContains(
			'The leave starts in 5 days, less than the 14 days of notice expected.',
			$this->emailBody,
		);
		self::assertSame(5, $this->pushed[0]['noticeDays']);
		self::assertSame(14, $this->pushed[0]['noticePeriod']);
	}

	public function testTheReasonStillTravelsAlongsideTheWarning(): void {
		// The two are separate concerns and the warning must not displace the note.
		$this->withUsers('emp', 'boss');
		$request = $this->request();
		$request->setReason('Family wedding abroad.');
		$this->notice->method('warningFor')->willReturn(['days' => 5, 'noticePeriod' => 14]);

		$this->service->notifyNewRequest($request, 'boss');

		self::assertSame([['Family wedding abroad.', 'Emp wrote:']], $this->emailNotes);
		self::assertSame('Family wedding abroad.', $this->pushed[0]['note']);
	}

	public function testAmpleNoticeSaysNothingAboutNotice(): void {
		$this->withUsers('emp', 'boss');
		$this->notice->method('warningFor')->willReturn(null);

		$this->service->notifyNewRequest($this->request(), 'boss');

		self::assertSame('New leave request from Emp', $this->emailSubject);
		self::assertNull($this->pushed[0]['noticeDays']);
		self::assertSame(
			['Emp requested leave for 2026-02-10 – 2026-02-12. Please review it in Absence.'],
			$this->emailBody,
		);
	}

	public function testADecisionCarriesNoNoticeWarning(): void {
		// Telling the employee their leave was approved is not the moment to point out
		// how late they asked; nobody is being asked to decide anything.
		$this->withUsers('emp', 'boss');
		$this->notice->expects(self::never())->method('warningFor');
		$request = $this->request();
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		$request->setDecidedBy('boss');

		$this->service->notifyDecision($request, true);

		self::assertNull($this->pushed[0]['noticeDays']);
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

	public function testAnEscalationIsNotSentToThePersonItIsAbout(): void {
		// An HR member cannot decide their own request, so they are not asked to.
		$this->withUsers('emp', 'hr1');
		$this->mailer->expects(self::once())->method('send');

		$this->service->notifyEscalation($this->request(), ['emp', 'hr1']);

		self::assertCount(1, $this->pushed);
	}

	public function testTheLineManagerIsToldWhoIsAbsentAndWhenAndNothingElse(): void {
		// The same message for every leave type: a confidential one (§5.7) must not
		// stand out, and its type and reason must not be stored for the manager.
		$this->withUsers('emp', 'boss');
		$this->mailer->expects(self::once())->method('send');
		$request = $this->request();
		$request->setTypeId(9);
		$request->setStatus(LeaveRequest::STATUS_APPROVED);
		$request->setReason('Parental leave, second child.');

		$this->service->notifyAbsenceRecorded($request, 'boss');

		self::assertCount(1, $this->pushed);
		self::assertSame('emp', $this->pushed[0]['employee']);
		self::assertSame('2026-02-10', $this->pushed[0]['start']);
		self::assertSame('2026-02-12', $this->pushed[0]['end']);
		self::assertFalse($this->pushed[0]['actionable']);
		self::assertSame('', $this->pushed[0]['note']);
		self::assertArrayNotHasKey('typeId', $this->pushed[0]);
		$stored = json_encode($this->pushed[0], JSON_THROW_ON_ERROR);
		self::assertStringNotContainsString('Parental', $stored);

		self::assertSame('Emp is absent', $this->emailSubject);
		self::assertSame(['HR recorded an absence for Emp for 2026-02-10 – 2026-02-12.'], $this->emailBody);
		self::assertSame([], $this->emailNotes);
	}

	/**
	 * @return array<string,array{0:\Closure(NotificationService,LeaveRequest):void}>
	 */
	public static function messagesToSomeoneOutsideHr(): array {
		return [
			'approval' => [static fn (NotificationService $service, LeaveRequest $request) => $service->notifyDecision($request, true)],
			'rejection' => [static fn (NotificationService $service, LeaveRequest $request) => $service->notifyDecision($request, false)],
			'declined withdrawal' => [static fn (NotificationService $service, LeaveRequest $request) => $service->notifyWithdrawalRejected($request, 'Itest written note.', 'hr1')],
			'reminder' => [static fn (NotificationService $service, LeaveRequest $request) => $service->notifyReminder($request, 'boss')],
		];
	}

	/**
	 * The employee and the manager see the dates and the status of a confidential
	 * request (§5.7), not what was written on it or who wrote it.
	 *
	 * @param \Closure(NotificationService,LeaveRequest):void $send
	 */
	#[DataProvider('messagesToSomeoneOutsideHr')]
	public function testAConfidentialRequestReachesSomeoneOutsideHrWithoutWhatWasWritten(\Closure $send): void {
		$this->withUsers('emp', 'boss', 'hr1');
		$this->confidential = true;
		$this->mailer->expects(self::once())->method('send');
		$request = $this->request();
		$request->setReason('Itest written note.');
		$request->setDecidedBy('hr1');
		$request->setDecisionComment('Itest written note.');

		$send($this->service, $request);

		self::assertCount(1, $this->pushed);
		self::assertSame('', $this->pushed[0]['note']);
		self::assertSame('', $this->pushed[0]['noteAuthor']);
		self::assertSame([], $this->emailNotes);
		self::assertStringNotContainsString('Itest written note', json_encode([$this->pushed, $this->emailBody], JSON_THROW_ON_ERROR));
	}

	public function testHrReadWhatWasWrittenOnAConfidentialRequest(): void {
		$this->withUsers('emp', 'hr1');
		$this->confidential = true;
		$request = $this->request();
		$request->setReason('Itest written note.');

		$this->service->notifyEscalation($request, ['hr1']);

		self::assertSame('Itest written note.', $this->pushed[0]['note']);
		self::assertSame('emp', $this->pushed[0]['noteAuthor']);
		self::assertSame([['Itest written note.', 'Emp wrote:']], $this->emailNotes);
	}

	public function testAMailThatFailsLogsATraceWithoutTheComment(): void {
		$this->keepTraceArguments();
		$this->withUsers('emp', 'boss');
		$this->mailer->method('send')->willReturnCallback(static function (): never {
			throw new \RuntimeException('Itest mail server unreachable');
		});
		$logged = null;
		$this->logger->method('warning')->willReturnCallback(function (string $message, array $context) use (&$logged): void {
			$logged = $context['exception'] ?? null;
		});

		$this->service->notifyWithdrawalRejected($this->request(), 'Itest private refusal', 'boss');

		self::assertInstanceOf(\RuntimeException::class, $logged);
		$arguments = $this->traceArguments($logged);
		self::assertContains('emp', $arguments);
		self::assertSame([], array_filter($arguments, static fn ($value): bool => is_string($value) && str_contains($value, 'Itest private')));
	}

	public function testCarryOverReminderGoesOutAsNotificationAndEmail(): void {
		$this->withUsers('robin');
		$this->mailer->expects(self::once())->method('send');

		$this->service->notifyCarryOverExpiring('robin', 3.0, '2032-03-31');

		self::assertSame([['days' => 3.0, 'date' => '2032-03-31']], $this->pushed);
		self::assertSame('Carried-over days are about to expire', $this->emailSubject);
		self::assertStringStartsWith('3 carried-over days expire on ', $this->emailBody[0]);
	}

	public function testCarryOverReminderSkipsADisabledAccount(): void {
		$this->withUsers('robin-disabled');
		$this->mailer->expects(self::never())->method('send');

		$this->service->notifyCarryOverExpiring('robin-disabled', 3.0, '2032-03-31');

		self::assertSame([], $this->pushed);
	}

	public function testAManagerChangeIsToldToTheEmployeeAndHrButNotToWhoeverMadeIt(): void {
		$this->withUsers('emp', 'hr1', 'hr2', 'hr3-disabled', 'carol');
		$this->mailer->expects(self::never())->method('send');

		$this->service->notifyManagerChanged('emp', ['boss'], ['carol'], 'hr2', ['hr1', 'hr2', 'hr3-disabled', 'emp']);

		self::assertSame(['emp', 'hr1'], $this->recipients);
		self::assertSame(['employee' => 'emp', 'actor' => 'hr2', 'before' => ['boss'], 'after' => ['carol']], $this->pushed[0]);
	}
}
