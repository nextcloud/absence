<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Controller\ConfigController;
use OCA\Absence\Controller\EmployeeController;
use OCA\Absence\Notification\Notifier;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\EmployeeJsonService;
use OCA\Absence\Service\EmployeeListService;
use OCA\Absence\Service\EmployeeService;
use OCA\Absence\Service\HolidayService;
use OCA\Absence\Service\NotificationService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\PersonalDefaultsService;
use OCA\Absence\Service\RegionReminderService;
use OCA\Absence\Service\SessionService;
use OCA\Absence\Tests\Integration\Fixtures\RecordingNotifications;
use OCP\AppFramework\Http;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * HR reminds the people without a holiday region code (§7.2), against a real server.
 */
class RegionReminderTest extends TestCase {
	private const HR = 'itest-remind-hr';
	private const WITH_CODE = 'itest-remind-set';
	private const WITHOUT_CODE = 'itest-remind-unset';
	private const COUNTRY_ONLY = 'itest-remind-country';
	/** @var list<int> */
	private array $holidays = [];
	private static bool $registered = false;

	protected function setUp(): void {
		parent::setUp();
		if (!self::$registered) {
			Server::get(IManager::class)->registerApp(RecordingNotifications::class);
			self::$registered = true;
		}
		RecordingNotifications::$sent = [];
		RecordingNotifications::$processed = [];
		$users = Server::get(IUserManager::class);
		foreach ([self::HR, self::WITH_CODE, self::WITHOUT_CODE, self::COUNTRY_ONLY] as $uid) {
			$users->createUser($uid, 'Itest-Password-remind-' . $uid);
		}
		$groups = Server::get(IGroupManager::class);
		($groups->get('hr') ?? $groups->createGroup('hr'))->addUser($users->get(self::HR));
		Server::get(ConfigService::class)->setPersonalValue(self::WITH_CODE, ConfigLexicon::KEY_HOLIDAY_COUNTRY, 'FR');
	}

	protected function tearDown(): void {
		foreach ($this->holidays as $id) {
			Server::get(HolidayService::class)->delete($id);
		}
		foreach ([self::HR, self::WITH_CODE, self::WITHOUT_CODE, self::COUNTRY_ONLY] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		parent::tearDown();
	}

	public function testOnlyPeopleWithoutACodeAreRemindedOncePerWeek(): void {
		$reminders = Server::get(RegionReminderService::class);
		$planned = array_column($reminders->plan()['send'], 'uid');
		self::assertContains(self::WITHOUT_CODE, $planned);
		self::assertNotContains(self::WITH_CODE, $planned);

		self::assertSame(Http::STATUS_OK, $this->employeeController(self::HR)->remind(false)->getStatus());
		self::assertSame(1, $this->sentTo(self::WITHOUT_CODE));
		self::assertSame(0, $this->sentTo(self::WITH_CODE));

		$again = $reminders->send(self::HR);
		self::assertContains(self::WITHOUT_CODE, array_column($again['recent'], 'uid'));
		self::assertSame(1, $this->sentTo(self::WITHOUT_CODE));
	}

	public function testTheReminderLinksToTheProfile(): void {
		Server::get(NotificationService::class)->notifyMissingRegion(self::WITHOUT_CODE);
		$notification = $this->notificationsFor(self::WITHOUT_CODE)[0];

		$prepared = Server::get(Notifier::class)->prepare($notification, 'en');

		self::assertSame('Please set your holiday region code', $prepared->getParsedSubject());
		self::assertStringEndsWith('#/profile', $prepared->getLink());
	}

	public function testSettingACodeTakesTheReminderBack(): void {
		$this->configController(self::WITHOUT_CODE)->updatePersonal(['holiday_country' => 'de', 'holiday_region' => 'by']);

		$taken = array_filter(RecordingNotifications::$processed, static fn (INotification $n): bool => $n->getObjectType() === 'absence_region' && $n->getUser() === self::WITHOUT_CODE);
		self::assertCount(1, $taken);
	}

	public function testACodeSetByHrTakesTheReminderBack(): void {
		Server::get(EmployeeService::class)->saveRegion(self::WITHOUT_CODE, 'DE-BY', self::HR);

		$taken = array_filter(RecordingNotifications::$processed, static fn (INotification $n): bool => $n->getObjectType() === 'absence_region' && $n->getUser() === self::WITHOUT_CODE);
		self::assertCount(1, $taken);
	}

	public function testACountryWithRegionalHolidaysIsNotEnough(): void {
		$this->holidays[] = Server::get(HolidayService::class)->create('2027-01-06', 'ZZ-T1', 'itest Region day')->getId();
		$config = Server::get(ConfigService::class);
		$config->setPersonalValue(self::COUNTRY_ONLY, ConfigLexicon::KEY_HOLIDAY_COUNTRY, 'ZZ');
		$config->setPersonalValue(self::WITH_CODE, ConfigLexicon::KEY_HOLIDAY_COUNTRY, 'ZY');

		$planned = array_column(Server::get(RegionReminderService::class)->plan()['send'], null, 'uid');

		self::assertSame(['ZZ', 'ZZ-T1'], [$planned[self::COUNTRY_ONLY]['code'], $planned[self::COUNTRY_ONLY]['example']]);
		self::assertArrayNotHasKey(self::WITH_CODE, $planned, 'a country without regional holidays is complete');

		Server::get(RegionReminderService::class)->send(self::HR);
		$prepared = Server::get(Notifier::class)->prepare($this->notificationsFor(self::COUNTRY_ONLY)[0], 'en');
		self::assertSame('Please complete your holiday region code', $prepared->getParsedSubject());
		self::assertStringContainsString('ZZ-T1', $prepared->getParsedMessage());

		$employees = Server::get(EmployeeService::class);
		$config->setPersonalValue(self::COUNTRY_ONLY, ConfigLexicon::KEY_HOLIDAY_COUNTRY, '');
		$employees->saveRegion(self::COUNTRY_ONLY, 'ZZ', self::HR);
		self::assertSame([], $this->takenBack(self::COUNTRY_ONLY), 'still only a country');
		$employees->saveRegion(self::COUNTRY_ONLY, 'ZZ-T1', self::HR);
		self::assertCount(1, $this->takenBack(self::COUNTRY_ONLY));
	}

	public function testOnlyHrSendsReminders(): void {
		$controller = $this->employeeController(self::WITHOUT_CODE);

		self::assertSame(Http::STATUS_FORBIDDEN, $controller->remind(true)->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->remind(false)->getStatus());
		self::assertSame([], RecordingNotifications::$sent);
	}

	/**
	 * @return list<INotification>
	 */
	private function notificationsFor(string $uid): array {
		return array_values(array_filter(RecordingNotifications::$sent, static fn (INotification $n): bool => $n->getUser() === $uid
			&& $n->getSubject() === NotificationService::SUBJECT_MISSING_REGION));
	}

	/**
	 * @return list<INotification>
	 */
	private function takenBack(string $uid): array {
		return array_values(array_filter(RecordingNotifications::$processed, static fn (INotification $n): bool => $n->getObjectType() === 'absence_region' && $n->getUser() === $uid));
	}

	private function sentTo(string $uid): int {
		return count($this->notificationsFor($uid));
	}

	private function employeeController(string $uid): EmployeeController {
		Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
		return new EmployeeController('absence', Server::get(IRequest::class), $uid, Server::get(IUserManager::class),
			Server::get(IGroupManager::class), Server::get(ISearch::class), Server::get(EmployeeDirectory::class),
			Server::get(EmployeeListService::class), Server::get(EmployeeJsonService::class), Server::get(RegionReminderService::class),
			Server::get(PermissionService::class), Server::get(LoggerInterface::class), Server::get(IFactory::class)->get('absence'));
	}

	private function configController(string $uid): ConfigController {
		Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
		return new ConfigController('absence', Server::get(IRequest::class), $uid, Server::get(ConfigService::class),
			Server::get(PersonalDefaultsService::class), Server::get(EmployeeService::class),
			Server::get(SessionService::class), Server::get(IFactory::class)->get('absence'));
	}
}
