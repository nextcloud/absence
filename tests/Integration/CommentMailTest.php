<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\BackgroundJob\CommentMailJob;
use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\CommentMailMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\RequestComment;
use OCA\Absence\Db\RequestCommentMapper;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\NoticeService;
use OCA\Absence\Service\NotificationService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Tests\Integration\Fixtures\RecordingNotifications;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Comments are pushed right away, and mailed together per request and
 * recipient once the thread has been quiet for a while (§11).
 */
class CommentMailTest extends TestCase {
	private const EMPLOYEE = 'itest-cmail-emp';
	private const MANAGER = 'itest-cmail-boss';
	private const HR = 'itest-cmail-hr';
	private const HR_GROUP = 'itest-cmail-hr';

	/** @var list<array{to: list<string>, text: string}> */
	private array $mails = [];
	private ?IEMailTemplate $template = null;
	private NotificationService $notifications;
	private LeaveRequest $request;
	private string $hrGroupBefore = '';

	protected function setUp(): void {
		parent::setUp();
		RecordingNotifications::register();
		$users = Server::get(IUserManager::class);
		foreach ([self::EMPLOYEE, self::MANAGER, self::HR] as $uid) {
			$users->createUser($uid, 'Itest-Password-cmail-' . $uid)->setSystemEMailAddress($uid . '@example.com');
		}
		$users->get(self::EMPLOYEE)->setManagerUids([self::MANAGER]);
		Server::get(IGroupManager::class)->createGroup(self::HR_GROUP)->addUser($users->get(self::HR));
		$appConfig = Server::get(IAppConfig::class);
		$this->hrGroupBefore = Server::get(ConfigService::class)->getHrGroup();
		$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, self::HR_GROUP);

		$request = new LeaveRequest();
		$request->setEmployeeUid(self::EMPLOYEE);
		$request->setManagerUid(self::MANAGER);
		$request->setTypeId(1);
		$request->setStartDate('2027-03-01');
		$request->setEndDate('2027-03-05');
		$request->setWorkingDays(5.0);
		$request->setStatus(LeaveRequest::STATUS_PENDING);
		$request->setCreatedAt(new \DateTime('2027-01-04 08:00:00'));
		$request->setUpdatedAt(new \DateTime('2027-01-04 08:00:00'));
		$this->request = Server::get(LeaveRequestMapper::class)->insert($request);

		$mailer = $this->createMock(IMailer::class);
		$real = Server::get(IMailer::class);
		$mailer->method('createEMailTemplate')->willReturnCallback(
			fn (string $id): IEMailTemplate => $this->template = $real->createEMailTemplate($id),
		);
		$mailer->method('createMessage')->willReturnCallback(static fn (): IMessage => $real->createMessage());
		$mailer->method('send')->willReturnCallback(function (IMessage $message): array {
			$this->mails[] = ['to' => array_keys($message->getTo()), 'text' => $this->template?->renderText() ?? ''];
			return [];
		});
		$this->notifications = new NotificationService(Server::get(INotificationManager::class), $mailer, $users,
			Server::get(IURLGenerator::class), Server::get(IFactory::class), Server::get(NoticeService::class),
			Server::get(LoggerInterface::class), Server::get(CommentMailMapper::class), Server::get(PermissionService::class));
	}

	protected function tearDown(): void {
		$db = Server::get(IDBConnection::class);
		foreach (['absence_comments', 'absence_comment_mails'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('request_id', $qb->createNamedParameter($this->request->getId(), IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		Server::get(LeaveRequestMapper::class)->delete($this->request);
		foreach ([self::EMPLOYEE, self::MANAGER, self::HR] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		Server::get(IGroupManager::class)->get(self::HR_GROUP)?->delete();
		Server::get(IAppConfig::class)->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, $this->hrGroupBefore);
		parent::tearDown();
	}

	public function testCommentsAreMailedTogetherOnceTheThreadIsQuiet(): void {
		$this->comment(self::MANAGER, 'Can you move this a day later?', '09:00');
		$this->comment(self::MANAGER, 'Or two days, if that suits you better.', '09:03');

		self::assertSame([], $this->mails);
		self::assertSame([self::EMPLOYEE, self::EMPLOYEE], $this->pushedTo());

		$this->runJob('09:12');
		self::assertSame([], $this->mails);

		$this->runJob('09:14');
		self::assertCount(1, $this->mails);
		self::assertSame([self::EMPLOYEE . '@example.com'], $this->mails[0]['to']);
		self::assertStringContainsString('Can you move this a day later?', $this->mails[0]['text']);
		self::assertStringContainsString('Or two days, if that suits you better.', $this->mails[0]['text']);

		$this->runJob('09:30');
		self::assertCount(1, $this->mails);
	}

	public function testNobodyIsMailedTheirOwnComments(): void {
		$this->comment(self::MANAGER, 'Can you move this a day later?', '09:00');
		$this->comment(self::EMPLOYEE, 'Yes, I will change the dates.', '09:05');

		$this->runJob('09:20');

		$byRecipient = array_column(array_map(static fn (array $mail): array => [$mail['to'][0], $mail['text']], $this->mails), 1, 0);
		self::assertEqualsCanonicalizing([self::EMPLOYEE . '@example.com', self::MANAGER . '@example.com'], array_keys($byRecipient));
		self::assertStringContainsString('Can you move this a day later?', $byRecipient[self::EMPLOYEE . '@example.com']);
		self::assertStringNotContainsString('Yes, I will change the dates.', $byRecipient[self::EMPLOYEE . '@example.com']);
		self::assertStringContainsString('Yes, I will change the dates.', $byRecipient[self::MANAGER . '@example.com']);
		self::assertStringNotContainsString('Can you move this a day later?', $byRecipient[self::MANAGER . '@example.com']);
	}

	public function testAConfidentialCommentIsMailedToHrOnly(): void {
		$this->comment(self::HR, 'Doctor\'s note received.', '09:00', confidential: true, recipients: [self::EMPLOYEE, self::MANAGER]);
		$this->comment(self::MANAGER, 'Noted, thanks.', '09:01', recipients: [self::HR]);

		$this->runJob('09:20');

		self::assertCount(1, $this->mails);
		self::assertSame([self::HR . '@example.com'], $this->mails[0]['to']);
	}

	public function testADeletedRecipientHasNoMailWaiting(): void {
		$this->comment(self::MANAGER, 'Can you move this a day later?', '09:00', recipients: [self::EMPLOYEE, self::HR]);

		Server::get(IUserManager::class)->get(self::HR)->delete();

		$waiting = Server::get(CommentMailMapper::class)->findForRequest($this->request->getId());
		self::assertSame([self::EMPLOYEE], array_map(static fn ($row): string => $row->getRecipientUid(), $waiting));
	}

	/**
	 * Store a comment on the request and tell the others, as RequestService does.
	 *
	 * @param list<string>|null $recipients the employee and the manager by default
	 */
	private function comment(string $author, string $body, string $time, bool $confidential = false, ?array $recipients = null): void {
		$comment = new RequestComment();
		$comment->setRequestId($this->request->getId());
		$comment->setAuthorUid($author);
		$comment->setBody($body);
		$comment->setConfidential($confidential);
		$comment->setCreatedAt(new \DateTime('2027-01-04 ' . $time . ':00', new \DateTimeZone('UTC')));
		$comment = Server::get(RequestCommentMapper::class)->insert($comment);
		$this->notifications->notifyComment($this->request, $comment, $recipients ?? [self::EMPLOYEE, self::MANAGER]);
	}

	private function runJob(string $time): void {
		$clock = $this->createMock(ClockService::class);
		$clock->method('now')->willReturn(new \DateTime('2027-01-04 ' . $time . ':00', new \DateTimeZone('UTC')));
		$job = new CommentMailJob(Server::get(ITimeFactory::class), $clock, Server::get(CommentMailMapper::class),
			Server::get(RequestCommentMapper::class), Server::get(LeaveRequestMapper::class), $this->notifications,
			Server::get(PermissionService::class));
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	/**
	 * @return list<string> who was pushed a comment notification about the request
	 */
	private function pushedTo(): array {
		$sent = array_filter(RecordingNotifications::$sent, fn (INotification $n): bool => $n->getSubject() === NotificationService::SUBJECT_COMMENT
			&& $n->getObjectId() === (string)$this->request->getId());
		return array_values(array_map(static fn (INotification $n): string => $n->getUser(), $sent));
	}
}
