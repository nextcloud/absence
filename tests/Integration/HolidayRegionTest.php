<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Api\IAbsenceProvider;
use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\HolidayMapper;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\HolidayService;
use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The holiday region code people set on their profile (§7.2), against a real server.
 */
class HolidayRegionTest extends TestCase {
	private const UID = 'itest-region-emp';
	/** History entries after this one are the test's own */
	private int $historyFrom = 0;

	protected function setUp(): void {
		parent::setUp();
		Server::get(IUserManager::class)->createUser(self::UID, 'Itest-Password-3');
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->max('id'))->from('absence_holiday_history');
		$this->historyFrom = (int)$qb->executeQuery()->fetchOne();
	}

	protected function tearDown(): void {
		Server::get(IUserManager::class)->get(self::UID)?->delete();
		$mapper = Server::get(HolidayMapper::class);
		foreach ($mapper->findInRange('0001-01-01', '9999-12-31', ['ZZ', 'ZZ-T1']) as $holiday) {
			$mapper->delete($holiday);
		}
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete('absence_holiday_history')
			->where($qb->expr()->gt('id', $qb->createNamedParameter($this->historyFrom, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		parent::tearDown();
	}

	public function testTheCodeIsNeverGuessedFromTheLocale(): void {
		Server::get(IUserConfig::class)->setValueString(self::UID, 'core', 'locale', 'de_AT');

		$settings = Server::get(IAbsenceProvider::class)->getWorkingDaysSettings(self::UID);

		self::assertNull($settings['holidayCountry']);
		self::assertNull($settings['holidayRegion']);
	}

	public function testTheCodeIsStoredAsSetAndChecked(): void {
		$config = Server::get(ConfigService::class);
		$config->setPersonalValue(self::UID, ConfigLexicon::KEY_HOLIDAY_COUNTRY, 'de');
		$config->setPersonalValue(self::UID, ConfigLexicon::KEY_HOLIDAY_REGION, 'by');

		$settings = Server::get(IAbsenceProvider::class)->getWorkingDaysSettings(self::UID);
		self::assertSame(['DE', 'BY'], [$settings['holidayCountry'], $settings['holidayRegion']]);

		$this->expectException(ValidationException::class);
		$config->setPersonalValue(self::UID, ConfigLexicon::KEY_HOLIDAY_COUNTRY, 'Germany');
	}

	public function testRegionsListsTheCodesThatHaveHolidays(): void {
		$service = Server::get(HolidayService::class);
		$service->create('2027-01-01', 'ZZ', 'itest Country day');
		$service->create('2027-01-06', 'ZZ-T1', 'itest Region day');

		$regions = $service->regions();

		self::assertContains('ZZ', $regions);
		self::assertContains('ZZ-T1', $regions);
		self::assertNotContains('ZY', $regions);
	}
}
