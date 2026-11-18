<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Listener;

use OCA\Absence\Listener\ManagerChangedListener;
use OCA\Absence\Service\EmployeeService;
use OCP\IUser;
use OCP\IUserSession;
use OCP\User\Events\UserChangedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ManagerChangedListenerTest extends TestCase {
	private EmployeeService&MockObject $employees;
	private IUserSession&MockObject $session;
	private LoggerInterface&MockObject $logger;
	private ManagerChangedListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->employees = $this->createMock(EmployeeService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new ManagerChangedListener($this->employees, $this->session, $this->logger);
	}

	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	public function testTheSignedInUserIsTheActor(): void {
		$this->session->method('getUser')->willReturn($this->user('subadmin'));
		$this->employees->expects(self::once())->method('recordManagerChange')
			->with('emp', ['boss'], ['subadmin'], 'subadmin');

		$this->listener->handle(new UserChangedEvent($this->user('emp'), 'managers', ['subadmin'], ['boss']));
	}

	public function testWithoutASessionThereIsNoActor(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->employees->expects(self::once())->method('recordManagerChange')
			->with('emp', [], ['boss'], null);

		$this->listener->handle(new UserChangedEvent($this->user('emp'), 'managers', ['boss'], null));
	}

	public function testOtherAccountChangesAreIgnored(): void {
		$this->employees->expects(self::never())->method('recordManagerChange');

		$this->listener->handle(new UserChangedEvent($this->user('emp'), 'displayName', 'Emp', 'Old'));
	}

	public function testAFailureIsLoggedAndDoesNotFailTheChange(): void {
		$this->employees->method('recordManagerChange')->willThrowException(new \RuntimeException('database gone'));
		$this->logger->expects(self::once())->method('error');

		$this->listener->handle(new UserChangedEvent($this->user('emp'), 'managers', ['boss'], []));
	}
}
