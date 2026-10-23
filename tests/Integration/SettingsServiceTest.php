<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Controller\SettingsController;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\HolidayService;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\PersonalDefaultsService;
use OCA\Absence\Service\SessionService;
use OCA\Absence\Service\SettingsService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The in-app settings page (§12) against a real server: who may read and
 * change what, and the employees-group change preview.
 */
class SettingsServiceTest extends TestCase {
	private const HR = 'itest-settings-hr';
	private const ADMIN = 'itest-settings-admin';
	private const EMPLOYEE = 'itest-settings-emp';
	private const PEOPLE = ['itest-settings-p1' => 'Itest Ana', 'itest-settings-p2' => 'Itest Ben', 'itest-settings-p3' => 'Itest Cleo'];
	private const HR_GROUP = 'itest-settings-hr';
	private const OTHER_GROUP = 'itest-settings-other';
	private const GROUP_A = 'itest-settings-a';
	private const GROUP_B = 'itest-settings-b';

	/** @var array<string,mixed> */
	private array $snapshot = [];
	/** Holiday history entries after this one are the test's own */
	private int $historyFrom = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->snapshot = Server::get(ConfigService::class)->getAdminConfig();
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->max('id'))->from('absence_holiday_history');
		$this->historyFrom = (int)$qb->executeQuery()->fetchOne();
		$groups = Server::get(IGroupManager::class);
		$users = Server::get(IUserManager::class);
		foreach ([self::HR_GROUP, self::OTHER_GROUP, self::GROUP_A, self::GROUP_B] as $gid) {
			$groups->createGroup($gid);
		}
		$groups->get(self::HR_GROUP)->addUser($users->createUser(self::HR, 'Itest-Password-1'));
		$groups->get('admin')->addUser($users->createUser(self::ADMIN, 'Itest-Password-2'));
		$users->createUser(self::EMPLOYEE, 'Itest-Password-3');
		foreach (self::PEOPLE as $uid => $name) {
			$users->createUser($uid, 'Itest-Password-4')->setDisplayName($name);
		}
		// A = p1 + p2, B = p2 + p3
		$groups->get(self::GROUP_A)->addUser($users->get('itest-settings-p1'));
		$groups->get(self::GROUP_A)->addUser($users->get('itest-settings-p2'));
		$groups->get(self::GROUP_B)->addUser($users->get('itest-settings-p2'));
		$groups->get(self::GROUP_B)->addUser($users->get('itest-settings-p3'));
		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, self::HR_GROUP);
		$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_EMPLOYEES_GROUP, '');
	}

	protected function tearDown(): void {
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
		$users = Server::get(IUserManager::class);
		foreach ([self::HR, self::ADMIN, self::EMPLOYEE, ...array_keys(self::PEOPLE)] as $uid) {
			$users->get($uid)?->delete();
		}
		$groups = Server::get(IGroupManager::class);
		foreach ([self::HR_GROUP, self::OTHER_GROUP, self::GROUP_A, self::GROUP_B] as $gid) {
			$groups->get($gid)?->delete();
		}
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete('absence_holiday_history')
			->where($qb->expr()->gt('id', $qb->createNamedParameter($this->historyFrom, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		parent::tearDown();
	}

	public function testOnlyAnAdminChangesTheHrGroup(): void {
		$response = $this->controller(self::HR)->update([ConfigLexicon::KEY_HR_GROUP => self::OTHER_GROUP]);
		self::assertSame(403, $response->getStatus());
		self::assertSame(self::HR_GROUP, $this->config()->getHrGroup());

		$response = $this->controller(self::ADMIN)->update([ConfigLexicon::KEY_HR_GROUP => self::OTHER_GROUP]);
		self::assertSame(200, $response->getStatus());
		self::assertSame(self::OTHER_GROUP, $this->config()->getHrGroup());
	}

	public function testTheSessionSaysWhoIsAnEmployee(): void {
		Server::get(IAppConfig::class)->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_EMPLOYEES_GROUP, self::GROUP_A);

		self::assertTrue($this->session('itest-settings-p1')['isEmployee']);
		self::assertFalse($this->session('itest-settings-p3')['isEmployee']);
		self::assertFalse($this->session(self::ADMIN)['isEmployee']);
	}

	public function testAnAdminOutsideHrReachesTheSettings(): void {
		self::assertFalse(Server::get(PermissionService::class)->isHr(self::ADMIN));
		$response = $this->controller(self::ADMIN)->show();
		self::assertSame(200, $response->getStatus());
		self::assertSame(self::HR_GROUP, $response->getData()['values'][ConfigLexicon::KEY_HR_GROUP]);
	}

	public function testHrChangesEverythingElse(): void {
		$response = $this->controller(self::HR)->update([
			ConfigLexicon::KEY_ESCALATION_WINDOW => 7,
			ConfigLexicon::KEY_CARRYOVER_POLICY => ConfigService::CARRYOVER_NONE,
			ConfigLexicon::KEY_CARRYOVER_EXPIRY => '03-31',
			ConfigLexicon::KEY_CALDAV_SHARED => false,
			ConfigLexicon::KEY_EMPLOYEES_GROUP => self::GROUP_A,
			// Unchanged, as a form saving every field sends it
			ConfigLexicon::KEY_HR_GROUP => self::HR_GROUP,
		]);

		self::assertSame(200, $response->getStatus());
		$config = $this->config();
		self::assertSame(
			[7, ConfigService::CARRYOVER_NONE, '03-31', false, self::GROUP_A],
			[$config->getEscalationWindowDays(), $config->getCarryOverPolicy(), $config->getCarryOverExpiry(), $config->isCalDavSharedEnabled(), $config->getEmployeesGroup()],
		);
		self::assertSame(2, $response->getData()['employeesCount']);
	}

	public function testAnEmployeeIsForbidden(): void {
		$controller = $this->controller(self::EMPLOYEE);

		self::assertSame(403, $controller->show()->getStatus());
		self::assertSame(403, $controller->update([ConfigLexicon::KEY_ESCALATION_WINDOW => 30])->getStatus());
		self::assertSame(403, $controller->employeesPreview(self::GROUP_A)->getStatus());
		self::assertSame($this->snapshot[ConfigLexicon::KEY_ESCALATION_WINDOW], $this->config()->getEscalationWindowDays());
	}

	public function testInvalidValuesAreRejected(): void {
		$invalid = [
			[ConfigLexicon::KEY_ESCALATION_WINDOW, -1],
			[ConfigLexicon::KEY_NOTICE_PERIOD, 'soon'],
			[ConfigLexicon::KEY_CARRYOVER_POLICY, 'banana'],
			[ConfigLexicon::KEY_CARRYOVER_EXPIRY, '31-03'],
			[ConfigLexicon::KEY_EMPLOYEES_GROUP, 'itest-settings-ghost'],
			[ConfigLexicon::KEY_HR_GROUP, 'itest-settings-ghost'],
			[ConfigLexicon::KEY_LAST_ROLLOVER_YEAR, 1999],
			[ConfigLexicon::KEY_HOLIDAYS_EVERYONE, 'false'],
		];
		$before = $this->config()->getAdminConfig();
		foreach ($invalid as [$key, $value]) {
			self::assertSame(422, $this->controller(self::ADMIN)->update([$key => $value])->getStatus(), $key);
		}
		self::assertSame($before, $this->config()->getAdminConfig());
	}

	public function testOneInvalidValueSavesNothing(): void {
		$before = $this->config()->getEscalationWindowDays();

		$response = $this->controller(self::HR)->update([
			ConfigLexicon::KEY_ESCALATION_WINDOW => $before + 4,
			ConfigLexicon::KEY_CARRYOVER_POLICY => 'banana',
		]);

		self::assertSame(422, $response->getStatus());
		self::assertSame($before, $this->config()->getEscalationWindowDays());
	}

	public function testHrLetsEveryoneEditHolidaysAndTheHistorySaysWho(): void {
		self::assertSame(403, $this->controller(self::EMPLOYEE)->update([ConfigLexicon::KEY_HOLIDAYS_EVERYONE => true])->getStatus());
		// One invalid value saves nothing, the switch included
		self::assertSame(422, $this->controller(self::HR)->update([
			ConfigLexicon::KEY_HOLIDAYS_EVERYONE => true,
			ConfigLexicon::KEY_ESCALATION_WINDOW => -1,
		])->getStatus());
		self::assertFalse($this->config()->canEveryoneEditHolidays());
		self::assertSame([], $this->holidayHistory());

		$response = $this->controller(self::HR)->update([ConfigLexicon::KEY_HOLIDAYS_EVERYONE => true]);

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['values'][ConfigLexicon::KEY_HOLIDAYS_EVERYONE]);
		self::assertTrue(Server::get(PermissionService::class)->canEditHolidays(self::EMPLOYEE));
		self::assertSame([[self::HR, 'everyone_edits']], $this->holidayHistory());
	}

	public function testChangesGoToTheAuditLog(): void {
		$messages = [];
		Server::get(IEventDispatcher::class)->addListener(CriticalActionPerformedEvent::class, static function (CriticalActionPerformedEvent $event) use (&$messages): void {
			$messages[] = vsprintf($event->getLogMessage(), $event->getParameters());
		});

		$this->controller(self::HR)->update([ConfigLexicon::KEY_EMPLOYEES_GROUP => self::GROUP_A]);

		self::assertContains('Absence setting employees_group set to "' . self::GROUP_A . '" by ' . self::HR, $messages);
	}

	public function testThePreviewNamesWhoLeavesAndWhoJoins(): void {
		$this->controller(self::HR)->update([ConfigLexicon::KEY_EMPLOYEES_GROUP => self::GROUP_A]);

		$response = $this->controller(self::HR)->employeesPreview(self::GROUP_B);

		self::assertSame(200, $response->getStatus());
		self::assertSame([
			'leaving' => ['count' => 1, 'names' => ['Itest Ana']],
			'joining' => ['count' => 1, 'names' => ['Itest Cleo']],
		], $response->getData());
		// Previewing changes nothing
		self::assertSame(self::GROUP_A, $this->config()->getEmployeesGroup());

		// Back to everyone: nobody leaves, the rest of the instance joins
		$everyone = $this->controller(self::HR)->employeesPreview('')->getData();
		self::assertSame(0, $everyone['leaving']['count']);
		self::assertGreaterThanOrEqual(4, $everyone['joining']['count']);

		self::assertSame(422, $this->controller(self::HR)->employeesPreview('itest-settings-ghost')->getStatus());
	}

	/**
	 * This test's holiday history entries, oldest first.
	 *
	 * @return list<array{?string, string}> actor and action
	 */
	private function holidayHistory(): array {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('actor_uid', 'action')
			->from('absence_holiday_history')
			->where($qb->expr()->gt('id', $qb->createNamedParameter($this->historyFrom, IQueryBuilder::PARAM_INT)))
			->orderBy('id');
		return array_map(static fn (array $row): array => [$row['actor_uid'], $row['action']], $qb->executeQuery()->fetchAll());
	}

	private function config(): ConfigService {
		return Server::get(ConfigService::class);
	}

	/**
	 * What the app is told about $uid on page load, with a fresh employee directory.
	 *
	 * @return array<string, mixed>
	 */
	private function session(string $uid): array {
		Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
		$directory = new EmployeeDirectory(Server::get(IUserManager::class), Server::get(IGroupManager::class), $this->config(), Server::get(LoggerInterface::class));
		return (new SessionService(Server::get(IUserSession::class), Server::get(IUserManager::class), Server::get(PermissionService::class), $directory,
			Server::get(ManagerResolver::class), Server::get(LeaveRequestMapper::class), Server::get(PersonalDefaultsService::class), $this->config()))->getSessionInfo();
	}

	/** A fresh controller per call, as each HTTP request gets its own services. */
	private function controller(string $uid): SettingsController {
		$l = Server::get(IFactory::class)->get('absence');
		$directory = new EmployeeDirectory(Server::get(IUserManager::class), Server::get(IGroupManager::class), $this->config(), Server::get(LoggerInterface::class));
		$service = new SettingsService($this->config(), Server::get(PermissionService::class), Server::get(HolidayService::class), $directory, Server::get(IGroupManager::class), Server::get(IUserManager::class), $l, Server::get(LoggerInterface::class), Server::get(IEventDispatcher::class));
		return new SettingsController('absence', Server::get(IRequest::class), $uid, $service, $l);
	}
}
