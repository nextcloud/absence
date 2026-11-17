<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\EmploymentMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\AbsenceException;
use OCA\Absence\Exception\NotFoundException;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeService;
use OCA\Absence\Service\EntitlementService;
use OCA\Absence\Service\RequestService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A user id from a request is the account's own spelling of it before it is
 * compared or stored (§2.2), against a real user backend: that is what finds
 * an account under another letter case, and under its e-mail address.
 */
class CanonicalUidTest extends TestCase {
	private const HR = 'itest-cu-hr';
	private const MANAGER = 'itest-cu-mgr';
	private const EMPLOYEE = 'Itest-Cu-Emp';
	private const EMPLOYEE_TYPED = 'itest-cu-emp';
	private const EMPLOYEE_EMAIL = 'emp.sample@example.com';
	private const COLLEAGUE = 'itest-cu-colleague';
	private const UNKNOWN = 'itest-cu-nobody';

	private RequestService $service;
	private LeaveType $type;
	private bool $createdHrGroup = false;

	protected function setUp(): void {
		parent::setUp();
		// No group holds the HR role until one is chosen (§12)
		Server::get(IAppConfig::class)->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, 'hr');
		$users = Server::get(IUserManager::class);
		foreach ([self::HR, self::MANAGER, self::EMPLOYEE, self::COLLEAGUE] as $i => $uid) {
			$users->createUser($uid, 'Itest-Password-cu-' . $i);
		}
		$users->get(self::EMPLOYEE)->setManagerUids([self::MANAGER]);
		$users->get(self::EMPLOYEE)->setSystemEMailAddress(self::EMPLOYEE_EMAIL);

		$groups = Server::get(IGroupManager::class);
		$hrGroup = Server::get(ConfigService::class)->getHrGroup();
		if (!$groups->groupExists($hrGroup)) {
			$groups->createGroup($hrGroup);
			$this->createdHrGroup = true;
		}
		$groups->get($hrGroup)?->addUser($users->get(self::HR));

		$type = new LeaveType();
		$type->setKey('itest_cu');
		$type->setLabel('Itest leave');
		$type->setCountsAgainstBalance(false);
		$this->type = Server::get(LeaveTypeMapper::class)->insert($type);
		$this->service = Server::get(RequestService::class);
	}

	protected function tearDown(): void {
		// What would be stored under a spelling no account has stays when the users go
		$db = Server::get(IDBConnection::class);
		$spellings = [strtoupper(self::HR), self::EMPLOYEE_TYPED, self::EMPLOYEE_EMAIL];
		foreach (['absence_requests', 'absence_entitlements', 'absence_ent_events', 'absence_employments', 'absence_periods', 'absence_emp_history'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in('employee_uid', $qb->createNamedParameter($spellings, IQueryBuilder::PARAM_STR_ARRAY)))
				->executeStatement();
		}
		foreach ([self::HR, self::MANAGER, self::EMPLOYEE, self::COLLEAGUE] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		Server::get(LeaveTypeMapper::class)->delete($this->type);
		if ($this->createdHrGroup) {
			Server::get(IGroupManager::class)->get(Server::get(ConfigService::class)->getHrGroup())?->delete();
		}
		Server::get(IAppConfig::class)->deleteKey(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP);
		parent::tearDown();
	}

	public function testOnesOwnUidInAnotherCaseIsNoReplacementForANewRequest(): void {
		self::assertSame(
			$this->refusal(fn () => $this->service->create(self::EMPLOYEE, $this->leave('2027-02-01', ['replacementUid' => self::EMPLOYEE]))),
			$this->refusal(fn () => $this->service->create(self::EMPLOYEE, $this->leave('2027-02-01', ['replacementUid' => self::EMPLOYEE_TYPED]))),
		);
		self::assertSame([], $this->service->list(self::EMPLOYEE, [], null, null));
	}

	public function testOnesOwnUidInAnotherCaseIsNoReplacementForAPendingRequest(): void {
		$request = $this->service->create(self::EMPLOYEE, $this->leave('2027-02-01'));

		self::assertSame(
			$this->refusal(fn () => $this->service->update(self::EMPLOYEE, $request->getId(), ['replacementUid' => self::EMPLOYEE])),
			$this->refusal(fn () => $this->service->update(self::EMPLOYEE, $request->getId(), ['replacementUid' => self::EMPLOYEE_TYPED])),
		);
	}

	public function testOnesOwnUidInAnotherCaseIsNoReplacementForAnApprovedRequest(): void {
		$request = $this->service->create(self::EMPLOYEE, $this->leave('2027-02-01'));
		$this->service->approve(self::MANAGER, $request->getId(), null);

		self::assertSame(
			$this->refusal(fn () => $this->service->update(self::EMPLOYEE, $request->getId(), ['replacementUid' => self::EMPLOYEE])),
			$this->refusal(fn () => $this->service->update(self::EMPLOYEE, $request->getId(), ['replacementUid' => self::EMPLOYEE_TYPED])),
		);
	}

	public function testHrCannotNameTheEmployeeInAnotherCaseAsTheirReplacement(): void {
		$request = $this->service->create(self::EMPLOYEE, $this->leave('2027-02-01'));

		self::assertSame(
			$this->refusal(fn () => $this->service->update(self::HR, $request->getId(), ['replacementUid' => self::EMPLOYEE])),
			$this->refusal(fn () => $this->service->update(self::HR, $request->getId(), ['replacementUid' => self::EMPLOYEE_TYPED])),
		);
	}

	public function testAReplacementIsStoredUnderTheAccountsOwnSpelling(): void {
		$request = $this->service->create(self::COLLEAGUE, $this->leave('2027-02-01', ['replacementUid' => self::EMPLOYEE_TYPED]));

		self::assertSame(self::EMPLOYEE, $request->getReplacementUid());
	}

	public function testLeaveRecordedForAnEmployeeInAnotherCaseIsTheirs(): void {
		$request = $this->service->create(self::HR, $this->leave('2027-03-01', ['employeeUid' => self::EMPLOYEE_TYPED]));

		self::assertSame(self::EMPLOYEE, $request->getEmployeeUid());
		self::assertSame(self::MANAGER, $request->getManagerUid());
		self::assertSame(
			[$request->getId()],
			array_map(static fn (LeaveRequest $r): int => $r->getId(), $this->service->list(self::EMPLOYEE, [], null, null)),
		);
	}

	public function testHrNamingThemselvesInAnotherCaseIsNotOnBehalf(): void {
		$own = $this->service->create(self::HR, $this->leave('2027-04-05'));
		$named = $this->service->create(self::HR, $this->leave('2027-04-12', ['employeeUid' => strtoupper(self::HR)]));

		self::assertNotSame(LeaveRequest::STATUS_APPROVED, $own->getStatus());
		self::assertSame($own->getStatus(), $named->getStatus());
		self::assertSame(self::HR, $named->getEmployeeUid());
		self::assertNull($named->getDecidedBy());
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function notAUid(): array {
		return [
			'nobody' => [self::UNKNOWN],
			'an e-mail address' => [self::EMPLOYEE_EMAIL],
		];
	}

	#[DataProvider('notAUid')]
	public function testLeaveIsOnlyRecordedForAUid(string $employeeUid): void {
		try {
			$this->service->create(self::HR, $this->leave('2027-03-01', ['employeeUid' => $employeeUid]));
			self::fail('Leave was recorded for ' . $employeeUid);
		} catch (ValidationException $e) {
			self::assertSame('Leave can only be recorded for an employee.', $e->getMessage());
		}
		self::assertSame([], $this->service->list(self::HR, ['scope' => 'hr', 'employeeUid' => $employeeUid], null, null));
	}

	public function testAnEntitlementSetInAnotherCaseIsTheEmployees(): void {
		$annual = array_values(array_filter(
			Server::get(LeaveTypeMapper::class)->findAll(),
			static fn (LeaveType $type): bool => $type->getKey() === 'annual',
		))[0];
		$entitlements = Server::get(EntitlementService::class);

		$entitlement = $entitlements->setForEmployee(self::HR, self::EMPLOYEE_TYPED, 2027, $annual->getId(), ['baseDays' => 28.0]);

		self::assertSame(self::EMPLOYEE, $entitlement->getEmployeeUid());
		self::assertSame([], $entitlements->listForEmployee(self::EMPLOYEE_TYPED, 2027));
	}

	public function testAContractSavedInAnotherCaseIsTheEmployees(): void {
		Server::get(EmployeeService::class)->saveContract(self::EMPLOYEE_TYPED, '2024-03-01', null, [], self::HR);

		$employments = Server::get(EmploymentMapper::class);
		self::assertSame('2024-03-01', $employments->findForEmployee(self::EMPLOYEE)?->getStartDate());
		self::assertNull($employments->findForEmployee(self::EMPLOYEE_TYPED));
	}

	public function testNoContractIsSavedForAnEMailAddress(): void {
		$this->expectException(NotFoundException::class);

		Server::get(EmployeeService::class)->saveContract(self::EMPLOYEE_EMAIL, '2024-03-01', null, [], self::HR);
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function leave(string $start, array $extra = []): array {
		return ['typeId' => $this->type->getId(), 'startDate' => $start, 'endDate' => $start, 'workingDays' => 1] + $extra;
	}

	/** What the caller is told when the action is refused. */
	private function refusal(\Closure $action): string {
		try {
			$action();
		} catch (AbsenceException $e) {
			return $e::class . ': ' . $e->getMessage();
		}
		self::fail('The action was not refused');
	}
}
