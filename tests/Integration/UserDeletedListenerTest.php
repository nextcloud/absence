<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\BackgroundJob\EscalationJob;
use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\NotificationService;
use OCA\Absence\Tests\Integration\Fixtures\RecordingNotifications;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Notification\INotification;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * What happens to other people's requests when an account is deleted (§17),
 * against a real server: the requests the deleted manager was still to decide
 * must end up with somebody who can.
 */
class UserDeletedListenerTest extends TestCase {
	private const EMPLOYEE = 'itest-deleted-emp';
	private const MANAGER = 'itest-deleted-mgr';
	private const HR = 'itest-deleted-hr';
	private const HR_GROUP = 'itest-deleted-hr';

	private LeaveRequestMapper $mapper;
	private string $hrGroupBefore = '';

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = Server::get(LeaveRequestMapper::class);
		RecordingNotifications::register();
		$users = Server::get(IUserManager::class);
		foreach ([self::EMPLOYEE, self::MANAGER] as $uid) {
			$users->createUser($uid, 'Itest-Password-deleted-' . $uid);
		}
		Server::get(IGroupManager::class)->createGroup(self::HR_GROUP)
			->addUser($users->createUser(self::HR, 'Itest-Password-deleted-hr'));
		$this->hrGroupBefore = Server::get(ConfigService::class)->getHrGroup();
		Server::get(IAppConfig::class)->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, self::HR_GROUP);
	}

	protected function tearDown(): void {
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from('absence_requests')
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter(self::EMPLOYEE)));
		$requestIds = array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		$qb = $db->getQueryBuilder();
		$qb->delete('absence_request_events')
			->where($qb->expr()->in('request_id', $qb->createNamedParameter($requestIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
		$qb = $db->getQueryBuilder();
		$qb->delete('absence_requests')
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter(self::EMPLOYEE)))
			->executeStatement();
		foreach ([self::EMPLOYEE, self::MANAGER, self::HR] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		Server::get(IGroupManager::class)->get(self::HR_GROUP)?->delete();
		Server::get(IAppConfig::class)->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, $this->hrGroupBefore);
		parent::tearDown();
	}

	public function testAPendingRequestOfTheDeletedManagerGoesToHr(): void {
		$request = $this->seed(LeaveRequest::STATUS_PENDING, self::MANAGER);

		Server::get(IUserManager::class)->get(self::MANAGER)->delete();

		$stored = $this->mapper->find($request->getId());
		self::assertNull($stored->getManagerUid());
		self::assertSame(LeaveRequest::STATUS_ESCALATED, $stored->getStatus());
		self::assertContains($request->getId(), $this->ids($this->mapper->findEscalated()), 'in the HR queue');
		self::assertSame([self::HR], $this->recipients(NotificationService::SUBJECT_ESCALATION, $request));
	}

	public function testHrIsAskedAgainAboutAWithdrawalTheDeletedManagerWasToDecide(): void {
		$request = $this->seed(LeaveRequest::STATUS_WITHDRAWAL_PENDING, self::MANAGER);

		Server::get(IUserManager::class)->get(self::MANAGER)->delete();

		self::assertSame(LeaveRequest::STATUS_WITHDRAWAL_PENDING, $this->mapper->find($request->getId())->getStatus());
		self::assertSame([self::HR], $this->recipients(NotificationService::SUBJECT_WITHDRAWAL, $request));
	}

	public function testADecidedRequestOfTheDeletedManagerStaysAsItIs(): void {
		$request = $this->seed(LeaveRequest::STATUS_APPROVED, self::MANAGER);

		Server::get(IUserManager::class)->get(self::MANAGER)->delete();

		$stored = $this->mapper->find($request->getId());
		self::assertNull($stored->getManagerUid());
		self::assertSame(LeaveRequest::STATUS_APPROVED, $stored->getStatus());
		self::assertSame([], RecordingNotifications::$sent);
	}

	public function testAPendingRequestWithoutAManagerIsEscalatedByTheJob(): void {
		// As left behind by a manager deleted before such requests were handed over
		$request = $this->seed(LeaveRequest::STATUS_PENDING, null);

		$job = Server::get(EscalationJob::class);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);

		self::assertSame(LeaveRequest::STATUS_ESCALATED, $this->mapper->find($request->getId())->getStatus());
		self::assertSame([self::HR], $this->recipients(NotificationService::SUBJECT_ESCALATION, $request));
	}

	private function seed(string $status, ?string $managerUid): LeaveRequest {
		$created = new \DateTime('-1 hour');
		$request = new LeaveRequest();
		$request->setEmployeeUid(self::EMPLOYEE);
		$request->setManagerUid($managerUid);
		$request->setTypeId(1);
		$request->setStartDate('2027-06-07');
		$request->setEndDate('2027-06-11');
		$request->setWorkingDays(5.0);
		$request->setStatus($status);
		$request->setCreatedAt($created);
		$request->setUpdatedAt($created);
		return $this->mapper->insert($request);
	}

	/**
	 * @param LeaveRequest[] $requests
	 * @return list<int>
	 */
	private function ids(array $requests): array {
		return array_map(static fn (LeaveRequest $request): int => $request->getId(), $requests);
	}

	/**
	 * @return list<string> who was sent this notification about the request
	 */
	private function recipients(string $subject, LeaveRequest $request): array {
		$sent = array_filter(RecordingNotifications::$sent, static fn (INotification $n): bool => $n->getSubject() === $subject
			&& $n->getObjectId() === (string)$request->getId());
		return array_values(array_map(static fn (INotification $n): string => $n->getUser(), $sent));
	}
}
