<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeService;
use OCA\Absence\Service\NotificationService;
use OCA\Absence\Service\ProfileService;
use OCA\Absence\Tests\Integration\Fixtures\RecordingNotifications;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\INotification;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Whoever may edit an account in core may change its manager (§2.1), against a
 * real server: the change lands in the employee's history, and the employee and
 * HR hear of it when a person made it.
 */
class ManagerChangeHistoryTest extends TestCase {
	private const EMPLOYEE = 'itest-mgrhist-emp';
	private const FORMER = 'itest-mgrhist-former';
	private const SUBADMIN = 'itest-mgrhist-subadmin';
	private const HR = 'itest-mgrhist-hr';
	private const HR_GROUP = 'itest-mgrhist-hr';

	protected function setUp(): void {
		parent::setUp();
		Server::get(IAppConfig::class)->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, self::HR_GROUP);
		$users = Server::get(IUserManager::class);
		foreach ([self::EMPLOYEE, self::FORMER, self::SUBADMIN, self::HR] as $i => $uid) {
			$users->createUser($uid, 'Itest-Password-mgrhist-' . $i);
		}
		$groups = Server::get(IGroupManager::class);
		($groups->get(self::HR_GROUP) ?? $groups->createGroup(self::HR_GROUP))->addUser($users->get(self::HR));
		Server::get(IUserSession::class)->setUser(null);
		$users->get(self::EMPLOYEE)->setManagerUids([self::FORMER]);
		RecordingNotifications::register();
	}

	protected function tearDown(): void {
		Server::get(IUserSession::class)->setUser(null);
		foreach ([self::EMPLOYEE, self::FORMER, self::SUBADMIN, self::HR] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		Server::get(IGroupManager::class)->get(self::HR_GROUP)?->delete();
		Server::get(IAppConfig::class)->deleteKey(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP);
		parent::tearDown();
	}

	public function testAChangeBySomeoneIsInTheHistoryAndToldToTheEmployeeAndHr(): void {
		$users = Server::get(IUserManager::class);
		Server::get(IUserSession::class)->setUser($users->get(self::SUBADMIN));

		$users->get(self::EMPLOYEE)->setManagerUids([self::SUBADMIN]);

		$latest = Server::get(EmployeeService::class)->history(self::EMPLOYEE)[0];
		self::assertSame(['manager', self::SUBADMIN, [self::FORMER], [self::SUBADMIN]], [$latest['field'], $latest['actor'], $latest['before'], $latest['after']]);
		$told = array_filter(RecordingNotifications::$sent, static fn (INotification $n): bool => $n->getSubject() === NotificationService::SUBJECT_MANAGER_CHANGED);
		self::assertEqualsCanonicalizing([self::EMPLOYEE, self::HR], array_map(static fn (INotification $n): string => $n->getUser(), $told));
	}

	public function testAChangeWithoutASessionIsInTheHistoryOnly(): void {
		Server::get(IUserManager::class)->get(self::EMPLOYEE)->setManagerUids([self::SUBADMIN]);
		// The same again is no change
		Server::get(IUserManager::class)->get(self::EMPLOYEE)->setManagerUids([self::SUBADMIN]);

		$history = Server::get(EmployeeService::class)->history(self::EMPLOYEE);
		self::assertSame([[self::SUBADMIN], [self::FORMER], null], [$history[0]['after'], $history[1]['after'], $history[0]['actor']]);
		self::assertCount(2, $history);
		self::assertSame([], RecordingNotifications::$sent);
	}

	public function testTheProfileNamesTheManagersAndForgetsADeletedOne(): void {
		$users = Server::get(IUserManager::class);
		$users->get(self::EMPLOYEE)->setManagerUids([self::SUBADMIN]);

		$users->get(self::FORMER)->delete();

		$history = Server::get(ProfileService::class)->get(self::HR, self::EMPLOYEE)['history'];
		self::assertSame([['uid' => '', 'displayName' => 'Deleted account']], $history[0]['before']);
		self::assertSame([['uid' => self::SUBADMIN, 'displayName' => self::SUBADMIN]], $history[0]['after']);
		self::assertSame([['uid' => '', 'displayName' => 'Deleted account']], $history[1]['after']);
	}
}
