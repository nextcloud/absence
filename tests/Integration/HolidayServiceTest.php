<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Controller\HolidayController;
use OCA\Absence\Db\Holiday;
use OCA\Absence\Db\HolidayMapper;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\HolidayService;
use OCA\Absence\Service\PermissionService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
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
 * The holiday list against a real database (§7.2). Uses the made-up country
 * ZZ and labels starting with "itest" so it never meets real data.
 */
class HolidayServiceTest extends TestCase {
	private const HR = 'itest-holiday-hr';
	private const EMPLOYEE = 'itest-holiday-emp';

	private HolidayService $service;
	/** History entries after this one are the test's own */
	private int $historyFrom = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(HolidayService::class);
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->max('id'))->from('absence_holiday_history');
		$this->historyFrom = (int)$qb->executeQuery()->fetchOne();
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
		Server::get(ConfigService::class)->setAdminValue(ConfigLexicon::KEY_HOLIDAYS_EVERYONE, false);
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete('absence_holiday_history')
			->where($qb->expr()->gt('id', $qb->createNamedParameter($this->historyFrom, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		parent::tearDown();
	}

	public function testImportAddsNewDaysAndSkipsKnownOnes(): void {
		$json = json_encode([
			['date' => '2027-01-01', 'region' => 'zz', 'label' => 'itest New Year'],
			['date' => '2027-01-06', 'region' => 'zz_t1', 'label' => 'itest Region day'],
			['date' => '2027-05-01', 'region' => 'ZZ', 'label' => 'itest Labour Day'],
			// Twice in the same file
			['date' => '2027-05-01', 'region' => 'ZZ', 'label' => 'itest Labour Day'],
		]);

		$first = $this->service->import($json);
		self::assertSame(['2027-01-01 ZZ', '2027-01-06 ZZ-T1', '2027-05-01 ZZ'], array_map(static fn (array $e): string => $e['date'] . ' ' . $e['region'], $first['new']));
		self::assertSame(1, $first['existing']);

		$again = $this->service->import($json);
		self::assertSame([], $again['new']);
		self::assertSame(4, $again['existing']);
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

	public function testOnlyHrMaintainsHolidaysByDefault(): void {
		$this->createPeople();

		self::assertSame(403, $this->controller(self::EMPLOYEE)->create('2027-08-15', 'ZZ', 'itest Assumption')->getStatus());
		self::assertSame([], $this->service->listForYear(2027, 'ZZ'));

		self::assertSame(200, $this->controller(self::HR)->create('2027-08-15', 'ZZ', 'itest Assumption')->getStatus());
		self::assertSame(['2027-08-15 ZZ'], self::describe($this->service->listForYear(2027, 'ZZ')));
		// Reading is for everyone
		self::assertSame(200, $this->controller(self::EMPLOYEE)->index(2027, 'ZZ')->getStatus());
	}

	public function testHrLetsEveryoneEditAndTheListShowsWho(): void {
		$this->createPeople();
		$audited = [];
		Server::get(IEventDispatcher::class)->addListener(CriticalActionPerformedEvent::class, static function (CriticalActionPerformedEvent $event) use (&$audited): void {
			$audited[] = vsprintf($event->getLogMessage(), $event->getParameters());
		});
		self::assertSame(403, $this->controller(self::EMPLOYEE)->editing(true)->getStatus());

		self::assertSame(200, $this->controller(self::HR)->editing(true)->getStatus());
		$created = $this->controller(self::EMPLOYEE)->create('2027-08-15', 'ZZ', 'itest Assumption');
		// Importing a whole list stays with HR
		self::assertSame(403, $this->controller(self::EMPLOYEE)->import('[{"date": "2027-01-01", "region": "ZZ", "label": "itest New Year"}]')->getStatus());
		self::assertSame(200, $created->getStatus());
		self::assertSame(200, $this->controller(self::HR)->update($created->getData()['id'], '2027-08-15', 'ZZ', 'itest Assumption Day')->getStatus());

		$row = $this->controller(self::EMPLOYEE)->index(2027, 'ZZ')->getData()[0];
		self::assertSame([self::EMPLOYEE, self::HR, 'itest Assumption Day'], [$row['createdBy'], $row['updatedBy'], $row['label']]);

		// The history says who did what, newest first
		$history = array_slice($this->controller(self::EMPLOYEE)->history()->getData(), 0, 3);
		self::assertSame(
			[[self::HR, 'changed'], [self::EMPLOYEE, 'added'], [self::HR, 'everyone_edits']],
			array_map(static fn (array $c): array => [$c['actorUid'], $c['action']], $history),
		);
		self::assertSame(['itest Assumption', 'itest Assumption Day'], [$history[0]['detail']['before']['label'], $history[0]['detail']['after']['label']]);

		// And the admin audit log, when that app is enabled
		self::assertSame([
			'Everyone may edit public holidays, set by ' . self::HR,
			'Public holiday "itest Assumption" added for ZZ on 2027-08-15 by ' . self::EMPLOYEE,
			'Public holiday "itest Assumption" for ZZ on 2027-08-15 changed to "itest Assumption Day" for ZZ on 2027-08-15 by ' . self::HR,
		], $audited);

		$this->controller(self::HR)->editing(false);
		self::assertSame(403, $this->controller(self::EMPLOYEE)->destroy($created->getData()['id'])->getStatus());
	}

	private function createPeople(): void {
		$groups = Server::get(IGroupManager::class);
		$hrGroupId = Server::get(ConfigService::class)->getHrGroup();
		$hrGroup = $groups->get($hrGroupId) ?? $groups->createGroup($hrGroupId);
		$users = Server::get(IUserManager::class);
		$hrGroup->addUser($users->createUser(self::HR, 'Itest-Password-1'));
		$users->createUser(self::EMPLOYEE, 'Itest-Password-2');
	}

	private function controller(string $uid): HolidayController {
		Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
		return new HolidayController('absence', Server::get(IRequest::class), $uid, $this->service, Server::get(PermissionService::class), Server::get(IFactory::class)->get('absence'), Server::get(IUserManager::class), Server::get(LoggerInterface::class));
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
