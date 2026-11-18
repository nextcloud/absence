<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Service;

use OCA\Absence\Db\ContractPeriodMapper;
use OCA\Absence\Db\EmployeeChange;
use OCA\Absence\Db\EmployeeChangeMapper;
use OCA\Absence\Db\EmploymentMapper;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\EmployeeService;
use OCA\Absence\Service\HolidayService;
use OCA\Absence\Service\NotificationService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Tests\Unit\ClockMockTrait;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A change of manager gives the new manager the employee's requests, balances
 * and profile (§2.1), so every change is kept and the people concerned hear of
 * the ones a person made.
 */
class EmployeeServiceTest extends TestCase {
	use ClockMockTrait;

	private EmployeeDirectory&MockObject $directory;
	private NotificationService&MockObject $notifications;
	private EmployeeService $service;
	/** @var list<EmployeeChange> */
	private array $recorded = [];
	/** @var list<CriticalActionPerformedEvent> */
	private array $audited = [];

	protected function setUp(): void {
		parent::setUp();
		$history = $this->createMock(EmployeeChangeMapper::class);
		$history->method('insert')->willReturnCallback(function (EmployeeChange $change): EmployeeChange {
			$this->recorded[] = $change;
			return $change;
		});
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			if ($event instanceof CriticalActionPerformedEvent) {
				$this->audited[] = $event;
			}
		});
		$this->directory = $this->createMock(EmployeeDirectory::class);
		$this->directory->method('isEmployee')->willReturnCallback(static fn (string $uid): bool => $uid !== 'guest');
		$this->notifications = $this->createMock(NotificationService::class);
		$permission = $this->createMock(PermissionService::class);
		$permission->method('getHrUids')->willReturn(['hr1', 'hr2']);

		$this->service = new EmployeeService(
			$this->createMock(EmploymentMapper::class),
			$this->createMock(ContractPeriodMapper::class),
			$history,
			$this->directory,
			$this->createMock(ConfigService::class),
			$this->clockAtRealTime(),
			$this->createMock(IDBConnection::class),
			$dispatcher,
			$this->notifications,
			$this->createMock(HolidayService::class),
			$permission,
			$this->createMock(IL10N::class),
		);
	}

	public function testAChangeBySomeoneIsKeptAuditedAndToldToTheEmployeeAndHr(): void {
		$this->notifications->expects(self::once())->method('notifyManagerChanged')
			->with('emp', ['boss'], ['carol'], 'carol', ['hr1', 'hr2']);

		$this->service->recordManagerChange('emp', ['boss'], ['carol'], 'carol');

		self::assertCount(1, $this->recorded);
		self::assertSame('emp', $this->recorded[0]->getEmployeeUid());
		self::assertSame(EmployeeChange::MANAGER, $this->recorded[0]->getField());
		self::assertSame('carol', $this->recorded[0]->getActorUid());
		self::assertSame(['before' => ['boss'], 'after' => ['carol']], json_decode($this->recorded[0]->getDetail(), true));
		self::assertCount(1, $this->audited);
		self::assertSame(['emp', 'boss', 'carol', 'carol'], $this->audited[0]->getParameters());
	}

	public function testAChangeWithoutAnyoneSignedInIsKeptButToldToNobody(): void {
		$this->notifications->expects(self::never())->method('notifyManagerChanged');

		$this->service->recordManagerChange('emp', [], ['boss'], null);

		self::assertCount(1, $this->recorded);
		self::assertNull($this->recorded[0]->getActorUid());
		self::assertSame(['emp', '', 'boss', 'system'], $this->audited[0]->getParameters());
	}

	public function testSettingTheSameManagersAgainIsNoChange(): void {
		$this->notifications->expects(self::never())->method('notifyManagerChanged');

		$this->service->recordManagerChange('emp', ['boss'], [' boss', '', 'boss'], 'admin');

		self::assertSame([], $this->recorded);
		self::assertSame([], $this->audited);
	}

	public function testAnotherOrderIsAChangeAsTheFirstValidManagerCounts(): void {
		$this->service->recordManagerChange('emp', ['boss', 'carol'], ['carol', 'boss'], 'admin');

		self::assertSame(['before' => ['boss', 'carol'], 'after' => ['carol', 'boss']], json_decode($this->recorded[0]->getDetail(), true));
	}

	public function testTheManagerOfSomeoneWhoIsNoEmployeeIsNotTracked(): void {
		$this->notifications->expects(self::never())->method('notifyManagerChanged');

		$this->service->recordManagerChange('guest', [], ['boss'], 'admin');

		self::assertSame([], $this->recorded);
		self::assertSame([], $this->audited);
	}
}
