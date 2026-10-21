<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Controller\HolidayController;
use OCA\Absence\Db\Holiday;
use OCA\Absence\Db\HolidayMapper;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\HolidayService;
use OCA\Absence\Service\PermissionService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The holiday list against a real database (§7.2). Uses the made-up country
 * ZZ and labels starting with "itest" so it never meets real data.
 */
class HolidayServiceTest extends TestCase {
	private const HR = 'itest-holiday-hr';
	private const EMPLOYEE = 'itest-holiday-emp';

	private HolidayService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(HolidayService::class);
	}

	protected function tearDown(): void {
		$mapper = Server::get(HolidayMapper::class);
		foreach ($mapper->findInRange('0001-01-01', '9999-12-31', ['ZZ', 'ZZ-T1', 'ZY', '*']) as $holiday) {
			if (str_starts_with($holiday->getLabel(), 'itest')) {
				$mapper->delete($holiday);
			}
		}
		foreach ([self::HR, self::EMPLOYEE] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		parent::tearDown();
	}

	public function testImportAddsNewDaysAndSkipsKnownOnes(): void {
		$json = json_encode([
			['date' => '2027-01-01', 'region' => 'zz', 'label' => 'itest New Year'],
			['date' => '2027-05-01', 'region' => 'ZZ', 'label' => 'itest Labour Day'],
			// Twice in the same file
			['date' => '2027-05-01', 'region' => 'ZZ', 'label' => 'itest Labour Day'],
		]);

		$first = $this->service->import($json);
		self::assertSame(['2027-01-01 ZZ', '2027-05-01 ZZ'], array_map(static fn (array $e): string => $e['date'] . ' ' . $e['region'], $first['new']));
		self::assertSame(1, $first['existing']);

		$again = $this->service->import($json);
		self::assertSame([], $again['new']);
		self::assertSame(3, $again['existing']);
	}

	public function testOneInvalidEntryImportsNothing(): void {
		try {
			$this->service->import(json_encode([
				['date' => '2027-01-01', 'region' => 'ZZ', 'label' => 'itest New Year'],
				['date' => '2027-02-30', 'region' => 'ZZ', 'label' => 'itest Not a day'],
				['date' => '2027-03-01', 'region' => 'Bavaria', 'label' => 'itest Not a code'],
			]));
			self::fail('An invalid entry must stop the import');
		} catch (ValidationException $e) {
			self::assertStringContainsString('Entry 2:', $e->getMessage());
			self::assertStringContainsString('Entry 3:', $e->getMessage());
		}
		self::assertSame([], $this->service->listForYear(2027, 'ZZ'));
	}

	public function testARegionGetsItsCountryAndCompanyWideDays(): void {
		$this->service->create('2027-01-01', 'ZZ', 'itest Country day');
		$this->service->create('2027-01-06', 'ZZ-T1', 'itest Region day');
		$this->service->create('2027-03-01', 'ZY', 'itest Other country');
		$this->service->create('2027-12-24', '*', 'itest Company day off');

		$days = $this->service->forRegions(['zz-t1'], '2027-01-01', '2027-12-31');

		self::assertSame(['2027-01-01 ZZ', '2027-01-06 ZZ-T1', '2027-12-24 *'], self::describe($days));
		// The country alone does not get its regions' days
		self::assertSame(['2027-01-01 ZZ', '2027-12-24 *'], self::describe($this->service->forRegions(['ZZ'], '2027-01-01', '2027-12-31')));
	}

	public function testRecurringDaysRepeatFromTheirFirstYear(): void {
		$this->service->create('2026-12-24', 'ZZ', 'itest Christmas Eve', recurring: true);
		$this->service->create('2024-02-29', 'ZZ', 'itest Leap day', recurring: true);

		self::assertSame(
			['2026-12-24 ZZ', '2027-12-24 ZZ', '2028-02-29 ZZ', '2028-12-24 ZZ'],
			self::describe($this->service->forRegions(['ZZ'], '2025-01-01', '2028-12-31')),
		);
	}

	public function testOnlyHrMaintainsHolidays(): void {
		$groups = Server::get(IGroupManager::class);
		$hrGroup = $groups->get(Server::get(ConfigService::class)->getHrGroup()) ?? $groups->createGroup(Server::get(ConfigService::class)->getHrGroup());
		$users = Server::get(IUserManager::class);
		$hrGroup->addUser($users->createUser(self::HR, 'Itest-Password-1'));
		$users->createUser(self::EMPLOYEE, 'Itest-Password-2');

		self::assertSame(403, $this->controller(self::EMPLOYEE)->create('2027-08-15', 'ZZ', 'itest Assumption')->getStatus());
		self::assertSame([], $this->service->listForYear(2027, 'ZZ'));

		self::assertSame(200, $this->controller(self::HR)->create('2027-08-15', 'ZZ', 'itest Assumption')->getStatus());
		self::assertSame(['2027-08-15 ZZ'], self::describe($this->service->listForYear(2027, 'ZZ')));
		// Reading is for everyone
		self::assertSame(200, $this->controller(self::EMPLOYEE)->index(2027, 'ZZ')->getStatus());
	}

	private function controller(string $uid): HolidayController {
		return new HolidayController('absence', Server::get(IRequest::class), $uid, $this->service, Server::get(PermissionService::class), Server::get(IFactory::class)->get('absence'), Server::get(LoggerInterface::class));
	}

	/**
	 * The days this test entered, other holidays on the instance aside.
	 *
	 * @param list<Holiday> $days
	 * @return list<string>
	 */
	private static function describe(array $days): array {
		return array_values(array_map(
			static fn (Holiday $h): string => $h->getDate() . ' ' . $h->getRegion(),
			array_filter($days, static fn (Holiday $h): bool => str_starts_with($h->getLabel(), 'itest')),
		));
	}
}
