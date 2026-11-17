<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Dashboard\AbsenceWidget;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Db\RequestEvent;
use OCA\Absence\Db\RequestEventMapper;
use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\LeaveImportService;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\RequestService;
use OCP\Config\IUserConfig;
use OCP\Dashboard\Model\WidgetItem;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Confidential leave types (§5.7) against a real server: what the employee and
 * their manager get for an absence whose category only HR sees.
 */
class ConfidentialTypesTest extends TestCase {
	private const HR = 'itest-conf-hr';
	private const MANAGER = 'itest-conf-manager';
	private const EMPLOYEE = 'itest-conf-emp';
	private const HR_GROUP = 'itest-conf-hr';
	private const TYPE_KEY = 'itest_conf';
	private const PLAIN_LABEL = 'Itest plain leave';
	private const CONFIDENTIAL_LABEL = 'Itest private matter';

	/** @var array<string,mixed> */
	private array $snapshot = [];
	private LeaveType $plainType;
	private LeaveType $confidentialType;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$users = Server::get(IUserManager::class);
		Server::get(IGroupManager::class)->createGroup(self::HR_GROUP)->addUser($users->createUser(self::HR, 'Itest-Password-conf-1'));
		$users->createUser(self::MANAGER, 'Itest-Password-conf-2');
		$users->createUser(self::EMPLOYEE, 'Itest-Password-conf-3')->setManagerUids([self::MANAGER]);
	}

	public static function tearDownAfterClass(): void {
		$users = Server::get(IUserManager::class);
		foreach ([self::HR, self::MANAGER, self::EMPLOYEE] as $uid) {
			$users->get($uid)?->delete();
		}
		Server::get(IGroupManager::class)->get(self::HR_GROUP)?->delete();
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->snapshot = Server::get(ConfigService::class)->getAdminConfig();
		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, self::HR_GROUP);

		$this->plainType = $this->type('_plain', self::PLAIN_LABEL, false);
		$this->confidentialType = $this->type('_private', self::CONFIDENTIAL_LABEL, true);
	}

	protected function tearDown(): void {
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from('absence_requests')
			->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter(self::EMPLOYEE)));
		$requestIds = array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		foreach (['absence_comments', 'absence_request_events'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in('request_id', $qb->createNamedParameter($requestIds, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('absence_requests')->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter(self::EMPLOYEE)))->executeStatement();
		$types = Server::get(LeaveTypeMapper::class);
		$types->delete($this->plainType);
		$types->delete($this->confidentialType);

		$appConfig = Server::get(IAppConfig::class);
		foreach ($this->snapshot as $key => $value) {
			$appConfig->deleteKey(ConfigService::APP_ID, $key);
			match (true) {
				is_bool($value) => $appConfig->setValueBool(ConfigService::APP_ID, $key, $value),
				is_int($value) => $appConfig->setValueInt(ConfigService::APP_ID, $key, $value),
				is_float($value) => $appConfig->setValueFloat(ConfigService::APP_ID, $key, $value),
				default => $appConfig->setValueString(ConfigService::APP_ID, $key, (string)$value),
			};
		}
		parent::tearDown();
	}

	public function testTheTypeFilterIgnoresConfidentialTypesForTheEmployeeAndTheManager(): void {
		$confidential = $this->request($this->confidentialType, LeaveRequest::STATUS_APPROVED, '2027-05-03');
		$plain = $this->request($this->plainType, LeaveRequest::STATUS_APPROVED, '2027-05-10');
		$byConfidentialType = ['typeId' => $this->confidentialType->getId()];
		$byPlainType = ['typeId' => $this->plainType->getId()];

		self::assertSame([], $this->listedIds(self::EMPLOYEE, $byConfidentialType));
		self::assertSame([], $this->listedIds(self::MANAGER, ['scope' => 'reports'] + $byConfidentialType));
		self::assertSame([$plain->getId()], $this->listedIds(self::EMPLOYEE, $byPlainType));
		self::assertSame([$plain->getId()], $this->listedIds(self::MANAGER, ['scope' => 'reports'] + $byPlainType));
		// Without a type filter both still list the absence itself
		self::assertSame([$plain->getId(), $confidential->getId()], $this->listedIds(self::EMPLOYEE, []));
		self::assertSame([$plain->getId(), $confidential->getId()], $this->listedIds(self::MANAGER, ['scope' => 'reports']));
		self::assertSame([$confidential->getId()], $this->listedIds(self::HR, ['scope' => 'hr', 'employeeUid' => self::EMPLOYEE] + $byConfidentialType));
	}

	public function testTheManagersWidgetListsOnlyRequestsTheyDecide(): void {
		// Pending with a confidential type, as when a type with open requests
		// becomes confidential
		$this->request($this->confidentialType, LeaveRequest::STATUS_PENDING, '2027-05-03');
		$plain = $this->request($this->plainType, LeaveRequest::STATUS_PENDING, '2027-05-10');

		$items = $this->widget()->getItems(self::MANAGER);

		self::assertSame(
			['approve-' . $plain->getId()],
			array_values(array_filter(
				array_map(static fn (WidgetItem $item): string => $item->getSinceId(), $items),
				static fn (string $id): bool => str_starts_with($id, 'approve-'),
			)),
		);
		self::assertStringNotContainsString(self::CONFIDENTIAL_LABEL, json_encode($items, JSON_THROW_ON_ERROR));
	}

	public function testOnlyHrCommentsOnAConfidentialAbsence(): void {
		$request = $this->request($this->confidentialType, LeaveRequest::STATUS_APPROVED, '2027-05-03');
		$service = Server::get(RequestService::class);

		foreach ([self::EMPLOYEE, self::MANAGER] as $uid) {
			try {
				$service->addComment($uid, $request->getId(), 'itest comment');
				self::fail($uid . ' commented on a confidential absence');
			} catch (ForbiddenException) {
			}
		}
		$service->addComment(self::HR, $request->getId(), 'itest comment');

		self::assertCount(1, $service->getDetail(self::HR, $request->getId())['comments']);
	}

	public function testTheHistoryRecordsAConfidentialTypeUnderTheNeutralWording(): void {
		$service = Server::get(RequestService::class);
		$request = $service->create(self::HR, [
			'typeId' => $this->confidentialType->getId(),
			'employeeUid' => self::EMPLOYEE,
			'startDate' => '2027-05-03',
			'endDate' => '2027-05-04',
			'workingDays' => 2,
		]);
		$service->update(self::HR, $request->getId(), ['typeId' => $this->plainType->getId()]);
		$service->update(self::HR, $request->getId(), ['typeId' => $this->confidentialType->getId()]);

		$details = array_map(
			static fn (RequestEvent $event): ?string => $event->getDetail(),
			Server::get(RequestEventMapper::class)->findForRequest($request->getId()),
		);

		self::assertSame([
			'Absent, 2027-05-03 – 2027-05-04 (2 days) · Recorded by HR',
			'Type Absent → ' . self::PLAIN_LABEL,
			'Type ' . self::PLAIN_LABEL . ' → Absent',
		], $details);
	}

	public function testWhatWasWrittenOnAConfidentialAbsenceStaysWithHrAfterATypeChange(): void {
		$service = Server::get(RequestService::class);
		$request = $this->recordConfidential('itest secret reason');
		$service->addComment(self::HR, $request->getId(), 'itest secret comment');
		$service->update(self::HR, $request->getId(), ['typeId' => $this->plainType->getId(), 'reason' => '']);

		foreach ([self::MANAGER, self::EMPLOYEE] as $uid) {
			$detail = $service->getDetail($uid, $request->getId());
			self::assertSame($this->plainType->getId(), $detail['typeId'], $uid);
			self::assertSame([], $detail['history'], $uid);
			self::assertSame([], $detail['comments'], $uid);
			self::assertStringNotContainsString('itest secret', json_encode($detail, JSON_THROW_ON_ERROR), $uid);
		}
		$detail = $service->getDetail(self::HR, $request->getId());
		self::assertSame([
			'Absent, 2027-05-03 – 2027-05-04 (2 days) · Reason: “itest secret reason” · Recorded by HR',
			'itest secret comment',
			'Type Absent → ' . self::PLAIN_LABEL . '; Reason cleared',
		], array_column($detail['history'], 'detail'));
		self::assertSame(['itest secret comment'], array_column($detail['comments'], 'body'));
	}

	public function testWhatIsWrittenAfterLeavingAConfidentialTypeIsShownAsUsual(): void {
		$service = Server::get(RequestService::class);
		$request = $this->recordConfidential('itest secret reason');
		$service->update(self::HR, $request->getId(), ['typeId' => $this->plainType->getId(), 'reason' => 'itest open reason']);
		$service->addComment(self::MANAGER, $request->getId(), 'itest open comment');
		$service->update(self::HR, $request->getId(), ['endDate' => '2027-05-05', 'workingDays' => 3]);

		foreach ([self::MANAGER, self::EMPLOYEE] as $uid) {
			$detail = $service->getDetail($uid, $request->getId());
			self::assertSame([
				'itest open comment',
				'Dates 2027-05-03 – 2027-05-04 → 2027-05-03 – 2027-05-05; Working days 2 days → 3 days (+1 day)',
			], array_column($detail['history'], 'detail'), $uid);
			self::assertSame(['itest open comment'], array_column($detail['comments'], 'body'), $uid);
		}
		self::assertCount(4, $service->getDetail(self::HR, $request->getId())['history']);
	}

	public function testWhatWasWrittenOnAConfidentialAbsenceStaysWithHrWhenTheTypeStopsBeingConfidential(): void {
		$service = Server::get(RequestService::class);
		$request = $this->recordConfidential('itest secret reason');
		$service->addComment(self::HR, $request->getId(), 'itest secret comment');
		$this->confidentialType->setHrOnly(false);
		Server::get(LeaveTypeMapper::class)->update($this->confidentialType);

		foreach ([self::MANAGER, self::EMPLOYEE] as $uid) {
			$detail = $service->getDetail($uid, $request->getId());
			self::assertSame([], $detail['history'], $uid);
			self::assertSame([], $detail['comments'], $uid);
		}
		self::assertCount(2, $service->getDetail(self::HR, $request->getId())['history']);
	}

	public function testTheHistoryOfAnImportedConfidentialAbsenceStaysWithHrAfterATypeChange(): void {
		$service = Server::get(RequestService::class);
		Server::get(LeaveImportService::class)->import(json_encode([[
			'user' => self::EMPLOYEE,
			'type' => $this->confidentialType->getKey(),
			'from' => '2027-05-03',
			'to' => '2027-05-04',
			'workingDays' => 2,
			'note' => 'itest secret note',
		]], JSON_THROW_ON_ERROR), self::HR);
		[$id] = $this->listedIds(self::HR, ['scope' => 'hr', 'employeeUid' => self::EMPLOYEE]);
		$service->update(self::HR, $id, ['typeId' => $this->plainType->getId(), 'reason' => '']);

		foreach ([self::MANAGER, self::EMPLOYEE] as $uid) {
			self::assertSame([], $service->getDetail($uid, $id)['history'], $uid);
		}
		self::assertCount(2, $service->getDetail(self::HR, $id)['history']);
	}

	public function testADeclinedWithdrawalOfAConfidentialAbsenceStaysWithHrAfterATypeChange(): void {
		$service = Server::get(RequestService::class);
		// A withdrawal waiting on a confidential type, as when a type with open
		// requests becomes confidential
		$request = $this->request($this->confidentialType, LeaveRequest::STATUS_WITHDRAWAL_PENDING, '2027-05-03');
		$service->reject(self::HR, $request->getId(), 'itest secret refusal');
		$service->update(self::HR, $request->getId(), ['typeId' => $this->plainType->getId()]);

		foreach ([self::MANAGER, self::EMPLOYEE] as $uid) {
			$detail = $service->getDetail($uid, $request->getId());
			self::assertSame([], $detail['history'], $uid);
			self::assertSame([], $detail['comments'], $uid);
		}
		self::assertSame(['Withdrawal declined: itest secret refusal'], array_column($service->getDetail(self::HR, $request->getId())['comments'], 'body'));
	}

	private function recordConfidential(string $reason): LeaveRequest {
		return Server::get(RequestService::class)->create(self::HR, [
			'typeId' => $this->confidentialType->getId(),
			'employeeUid' => self::EMPLOYEE,
			'startDate' => '2027-05-03',
			'endDate' => '2027-05-04',
			'workingDays' => 2,
			'reason' => $reason,
		]);
	}

	private function type(string $suffix, string $label, bool $hrOnly): LeaveType {
		$type = new LeaveType();
		$type->setKey(self::TYPE_KEY . $suffix);
		$type->setLabel($label);
		$type->setCountsAgainstBalance(false);
		$type->setHrOnly($hrOnly);
		$type->setEmployeeRequestable(!$hrOnly);
		return Server::get(LeaveTypeMapper::class)->insert($type);
	}

	private function request(LeaveType $type, string $status, string $start): LeaveRequest {
		$now = new \DateTime();
		$request = new LeaveRequest();
		$request->setEmployeeUid(self::EMPLOYEE);
		$request->setManagerUid(self::MANAGER);
		$request->setTypeId($type->getId());
		$request->setStatus($status);
		$request->setStartDate($start);
		$request->setEndDate($start);
		$request->setWorkingDays(1.0);
		$request->setCreatedAt($now);
		$request->setUpdatedAt($now);
		return Server::get(LeaveRequestMapper::class)->insert($request);
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return list<int>
	 */
	private function listedIds(string $uid, array $filters): array {
		return array_column(Server::get(RequestService::class)->listSerialized($uid, $filters, null, null), 'id');
	}

	/**
	 * The widget with a fresh resolver, as each request gets one: the shared
	 * one keeps who reports to whom from the tests that ran before.
	 */
	private function widget(): AbsenceWidget {
		$logger = Server::get(LoggerInterface::class);
		$resolver = new ManagerResolver(Server::get(IUserManager::class), Server::get(IUserConfig::class), Server::get(EmployeeDirectory::class), $logger);
		$l = Server::get(IFactory::class)->get(ConfigService::APP_ID, 'en');
		$types = Server::get(LeaveTypeMapper::class);
		return new AbsenceWidget(
			$l,
			Server::get(IURLGenerator::class),
			Server::get(IUserManager::class),
			Server::get(LeaveRequestMapper::class),
			Server::get(ClockService::class),
			$types,
			Server::get(BalanceService::class),
			new PermissionService(Server::get(IGroupManager::class), $resolver, Server::get(ConfigService::class), $types, $l, $logger, Server::get(EmployeeDirectory::class)),
			$resolver,
		);
	}
}
