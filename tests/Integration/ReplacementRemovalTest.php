<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\RequestService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * An edit that sends an empty replacement removes the stored one (§5.1),
 * against a real server, on each path an edit can take (§5.3, §5.5).
 */
class ReplacementRemovalTest extends TestCase {
	private const HR = 'itest-norep-hr';
	private const EMPLOYEE = 'itest-norep-emp';
	private const COLLEAGUE = 'itest-norep-colleague';
	private const HR_GROUP = 'itest-norep-hr';
	private const TYPE_KEY = 'itest_norep';

	/** @var array<string,mixed> */
	private array $snapshot = [];
	private RequestService $service;
	private LeaveRequestMapper $mapper;
	private LeaveType $type;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$users = Server::get(IUserManager::class);
		Server::get(IGroupManager::class)->createGroup(self::HR_GROUP)->addUser($users->createUser(self::HR, 'Itest-Password-norep-1'));
		$users->createUser(self::EMPLOYEE, 'Itest-Password-norep-2');
		$users->createUser(self::COLLEAGUE, 'Itest-Password-norep-3');
	}

	public static function tearDownAfterClass(): void {
		$users = Server::get(IUserManager::class);
		foreach ([self::HR, self::EMPLOYEE, self::COLLEAGUE] as $uid) {
			$users->get($uid)?->delete();
		}
		Server::get(IGroupManager::class)->get(self::HR_GROUP)?->delete();
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(RequestService::class);
		$this->mapper = Server::get(LeaveRequestMapper::class);
		$this->snapshot = Server::get(ConfigService::class)->getAdminConfig();
		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, self::HR_GROUP);
		$appConfig->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_CALDAV_SHARED, false);

		$type = new LeaveType();
		$type->setKey(self::TYPE_KEY);
		$type->setLabel('Itest leave without a replacement');
		$type->setCountsAgainstBalance(false);
		$this->type = Server::get(LeaveTypeMapper::class)->insert($type);
	}

	protected function tearDown(): void {
		$db = Server::get(IDBConnection::class);
		$requests = $this->mapper->findFiltered(['employeeUid' => self::EMPLOYEE]);
		$requestIds = array_map(static fn (LeaveRequest $request): int => $request->getId(), $requests);
		foreach (['absence_comments', 'absence_request_events'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in('request_id', $qb->createNamedParameter($requestIds, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		}
		foreach ($requests as $request) {
			$this->mapper->delete($request);
		}
		Server::get(LeaveTypeMapper::class)->delete($this->type);

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

	public function testTheEmployeeRemovesTheReplacementOfAPendingRequest(): void {
		$request = $this->seed(LeaveRequest::STATUS_PENDING);

		$this->service->update(self::EMPLOYEE, $request->getId(), ['replacementUid' => '']);

		self::assertNull($this->mapper->find($request->getId())->getReplacementUid());
	}

	public function testTheEmployeeRemovesTheReplacementWhenEditingApprovedLeave(): void {
		$request = $this->seed(LeaveRequest::STATUS_APPROVED);

		$edit = $this->service->update(self::EMPLOYEE, $request->getId(), ['endDate' => '2027-06-10', 'workingDays' => 4, 'replacementUid' => '']);

		self::assertSame($request->getId(), $edit->getSupersedesId());
		self::assertNull($this->mapper->find($edit->getId())->getReplacementUid());
	}

	public function testHrRemovesTheReplacement(): void {
		$request = $this->seed(LeaveRequest::STATUS_APPROVED);

		$this->service->update(self::HR, $request->getId(), ['replacementUid' => '']);

		self::assertNull($this->mapper->find($request->getId())->getReplacementUid());
	}

	public function testAnEditThatDoesNotNameTheReplacementKeepsIt(): void {
		$request = $this->seed(LeaveRequest::STATUS_PENDING);

		$this->service->update(self::EMPLOYEE, $request->getId(), ['reason' => 'Moving house']);

		self::assertSame(self::COLLEAGUE, $this->mapper->find($request->getId())->getReplacementUid());
	}

	private function seed(string $status): LeaveRequest {
		$request = new LeaveRequest();
		$request->setEmployeeUid(self::EMPLOYEE);
		$request->setTypeId($this->type->getId());
		$request->setStatus($status);
		$request->setStartDate('2027-06-07');
		$request->setEndDate('2027-06-11');
		$request->setWorkingDays(5.0);
		$request->setReplacementUid(self::COLLEAGUE);
		$request->setCreatedAt(new \DateTime('2027-01-04 09:00:00'));
		$request->setUpdatedAt(new \DateTime('2027-01-04 09:00:00'));
		return $this->mapper->insert($request);
	}
}
