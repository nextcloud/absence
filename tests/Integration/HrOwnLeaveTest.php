<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Db\RequestEventMapper;
use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\RequestService;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The absences an HR member records for themselves (§5.6), against a real
 * server: leave that has to be requested stays with a second person.
 */
class HrOwnLeaveTest extends TestCase {
	private const HR = 'itest-own-hr';
	private const OTHER_HR = 'itest-own-hr2';
	private const EMPLOYEE = 'itest-own-emp';

	private RequestService $service;
	private LeaveType $recorded;
	private LeaveType $alsoRecorded;
	private LeaveType $requested;
	private bool $createdHrGroup = false;

	protected function setUp(): void {
		parent::setUp();
		$users = Server::get(IUserManager::class);
		$groups = Server::get(IGroupManager::class);
		$hrGroup = Server::get(ConfigService::class)->getHrGroup();
		if (!$groups->groupExists($hrGroup)) {
			$groups->createGroup($hrGroup);
			$this->createdHrGroup = true;
		}
		foreach ([self::HR, self::OTHER_HR, self::EMPLOYEE] as $i => $uid) {
			$user = $users->createUser($uid, 'Itest-Password-own-' . $i);
			if ($uid !== self::EMPLOYEE) {
				$groups->get($hrGroup)?->addUser($user);
			}
		}

		$this->recorded = $this->type('recorded', false);
		$this->alsoRecorded = $this->type('recorded2', false);
		$this->requested = $this->type('requested', true);
		$this->service = Server::get(RequestService::class);
	}

	protected function tearDown(): void {
		foreach ([self::HR, self::OTHER_HR, self::EMPLOYEE] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		foreach ([$this->recorded, $this->alsoRecorded, $this->requested] as $type) {
			Server::get(LeaveTypeMapper::class)->delete($type);
		}
		if ($this->createdHrGroup) {
			Server::get(IGroupManager::class)->get(Server::get(ConfigService::class)->getHrGroup())?->delete();
		}
		parent::tearDown();
	}

	public function testAnHrMemberCannotTurnTheirOwnRecordIntoLeaveThatIsRequested(): void {
		$record = $this->record(self::HR);
		self::assertSame(LeaveRequest::STATUS_APPROVED, $record->getStatus());

		try {
			$this->service->update(self::HR, $record->getId(), ['typeId' => $this->requested->getId()]);
			self::fail('The HR member gave themselves approved leave of a type that is requested');
		} catch (ForbiddenException $e) {
			self::assertSame('Somebody else from HR has to change your own absence to this leave type.', $e->getMessage());
		}

		self::assertSame($this->recorded->getId(), Server::get(LeaveRequestMapper::class)->find($record->getId())->getTypeId());
		self::assertCount(1, Server::get(RequestEventMapper::class)->findForRequest($record->getId()));
	}

	public function testAnHrMemberChangesTheirOwnRecordToAnotherRecordedType(): void {
		$record = $this->record(self::HR);

		$changed = $this->service->update(self::HR, $record->getId(), ['typeId' => $this->alsoRecorded->getId(), 'endDate' => '2027-05-04', 'workingDays' => 2]);

		self::assertSame($this->alsoRecorded->getId(), $changed->getTypeId());
		self::assertSame('2027-05-04', $changed->getEndDate());
	}

	public function testAnotherHrMemberChangesTheRecordToLeaveThatIsRequested(): void {
		$record = $this->record(self::HR);

		$changed = $this->service->update(self::OTHER_HR, $record->getId(), ['typeId' => $this->requested->getId()]);

		self::assertSame($this->requested->getId(), $changed->getTypeId());
	}

	public function testHrChangesTheTypeOfSomebodyElsesRecord(): void {
		$record = $this->record(self::EMPLOYEE);

		$changed = $this->service->update(self::HR, $record->getId(), ['typeId' => $this->requested->getId()]);

		self::assertSame($this->requested->getId(), $changed->getTypeId());
		self::assertSame(LeaveRequest::STATUS_APPROVED, $changed->getStatus());
	}

	/** An absence of the recorded type that the first HR member books for $employeeUid. */
	private function record(string $employeeUid): LeaveRequest {
		return $this->service->create(self::HR, [
			'typeId' => $this->recorded->getId(),
			'employeeUid' => $employeeUid,
			'startDate' => '2027-05-03',
			'endDate' => '2027-05-03',
			'workingDays' => 1,
		]);
	}

	private function type(string $suffix, bool $requestable): LeaveType {
		$type = new LeaveType();
		$type->setKey('itest_own_' . $suffix);
		$type->setLabel('Itest ' . $suffix);
		$type->setCountsAgainstBalance(false);
		$type->setEmployeeRequestable($requestable);
		$type->setRequiresApproval($requestable);
		return Server::get(LeaveTypeMapper::class)->insert($type);
	}
}
