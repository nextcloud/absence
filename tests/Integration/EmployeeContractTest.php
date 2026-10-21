<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Controller\ConfigController;
use OCA\Absence\Controller\ProfileController;
use OCA\Absence\Db\ContractPeriodMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Exception\NotFoundException;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeService;
use OCA\Absence\Service\EntitlementService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\PersonalDefaultsService;
use OCA\Absence\Service\ProfileService;
use OCA\Absence\Service\RequestService;
use OCA\Absence\Service\SessionService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The contract data HR keeps per employee and the profile page (§6.4), against a
 * real server.
 */
class EmployeeContractTest extends TestCase {
	private const HR = 'itest-contract-hr';
	private const EMPLOYEE = 'itest-contract-emp';
	private const MANAGER = 'itest-contract-mgr';
	private const OTHER = 'itest-contract-other';

	private EmployeeService $service;

	protected function setUp(): void {
		parent::setUp();
		$users = Server::get(IUserManager::class);
		foreach ([self::HR, self::EMPLOYEE, self::MANAGER, self::OTHER] as $i => $uid) {
			$users->createUser($uid, 'Itest-Password-' . ($i + 10));
		}
		$groups = Server::get(IGroupManager::class);
		($groups->get('hr') ?? $groups->createGroup('hr'))->addUser($users->get(self::HR));
		$users->get(self::EMPLOYEE)->setManagerUids([self::MANAGER]);
		$this->service = Server::get(EmployeeService::class);
	}

	protected function tearDown(): void {
		foreach ([self::HR, self::EMPLOYEE, self::MANAGER, self::OTHER] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		parent::tearDown();
	}

	public function testTheWorkingPatternChangesFromItsDate(): void {
		$this->service->saveContract(self::EMPLOYEE, '2024-03-01', null, [
			['from' => '2026-07-01', 'weekdays' => [4, 1, 2, 3], 'percent' => 80],
			['from' => '2024-03-01', 'weekdays' => [1, 2, 3, 4, 5], 'percent' => 100],
		], self::HR);

		self::assertNull($this->service->weekdaysOn(self::EMPLOYEE, '2024-02-29'));
		self::assertSame([1, 2, 3, 4, 5], $this->service->weekdaysOn(self::EMPLOYEE, '2026-06-30'));
		self::assertSame([1, 2, 3, 4], $this->service->weekdaysOn(self::EMPLOYEE, '2026-07-01'));
		self::assertSame(['2024-03-01', '2026-07-01'], array_column($this->service->getContract(self::EMPLOYEE)['periods'], 'from'));
	}

	public function testThePrefillUsesTheContractOverMondayToFriday(): void {
		$defaults = Server::get(PersonalDefaultsService::class);
		self::assertSame(ConfigService::DEFAULT_WORK_WEEKDAYS, $defaults->resolve(self::EMPLOYEE)['workWeekdays']);

		$this->service->saveContract(self::EMPLOYEE, null, null, [['from' => '2000-01-01', 'weekdays' => [2, 3, 4, 5], 'percent' => 80]], self::HR);

		self::assertSame('2,3,4,5', $defaults->resolve(self::EMPLOYEE)['workWeekdays']);
	}

	/**
	 * @param list<array<string, mixed>> $periods
	 */
	#[DataProvider('invalidContracts')]
	public function testInvalidContractsAreRefused(?string $start, ?string $end, array $periods): void {
		$this->expectException(ValidationException::class);
		$this->service->saveContract(self::EMPLOYEE, $start, $end, $periods, self::HR);
	}

	/**
	 * @return array<string, array{?string, ?string, list<array<string, mixed>>}>
	 */
	public static function invalidContracts(): array {
		return [
			'end before start' => ['2026-05-01', '2026-04-30', []],
			'not a day' => ['2026-02-30', null, []],
			'twice the same date' => [null, null, [
				['from' => '2026-07-01', 'weekdays' => [1], 'percent' => 80],
				['from' => '2026-07-01', 'weekdays' => [2], 'percent' => 80],
			]],
			'no working day' => [null, null, [['from' => '2026-07-01', 'weekdays' => [], 'percent' => 80]]],
			'day 8' => [null, null, [['from' => '2026-07-01', 'weekdays' => [8], 'percent' => 80]]],
			'0 percent' => [null, null, [['from' => '2026-07-01', 'weekdays' => [1], 'percent' => 0]]],
		];
	}

	public function testOnlyEmployeesHaveAContract(): void {
		$this->expectException(NotFoundException::class);
		$this->service->saveContract('itest-nobody', null, null, [], self::HR);
	}

	public function testOnlyHrSavesAContract(): void {
		$body = ['2024-03-01', null, [['from' => '2024-03-01', 'weekdays' => [1, 2, 3], 'percent' => 60]]];

		self::assertSame(Http::STATUS_FORBIDDEN, $this->profileController(self::MANAGER)->updateContract(self::EMPLOYEE, ...$body)->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->profileController(self::EMPLOYEE)->updateContract(self::EMPLOYEE, ...$body)->getStatus());
		self::assertSame([], $this->service->getContract(self::EMPLOYEE)['periods']);

		self::assertSame(Http::STATUS_OK, $this->profileController(self::HR)->updateContract(self::EMPLOYEE, ...$body)->getStatus());
		self::assertSame([1, 2, 3], $this->service->weekdaysOn(self::EMPLOYEE, '2026-01-01'));
	}

	public function testTheProfileIsForThePersonTheirManagerAndHr(): void {
		$profiles = Server::get(ProfileService::class);
		$this->service->saveContract(self::EMPLOYEE, '2024-03-01', null, [['from' => '2024-03-01', 'weekdays' => [1, 2, 3, 4], 'percent' => 80]], self::HR);

		foreach ([self::EMPLOYEE, self::MANAGER, self::HR] as $viewer) {
			self::assertSame(80, $profiles->get($viewer, self::EMPLOYEE)['contract']['periods'][0]['percent'], $viewer);
		}
		self::assertSame(self::MANAGER, $profiles->get(self::HR, self::EMPLOYEE)['manager']['uid']);

		$this->expectException(ForbiddenException::class);
		$profiles->get(self::OTHER, self::EMPLOYEE);
	}

	public function testTheProfileCountsSickDaysAndFindsTheNextLeave(): void {
		$year = (int)date('Y');
		$requests = Server::get(RequestService::class);
		$record = fn (string $type, string $from, string $to, float $days) => $requests->create(self::HR, [
			'employeeUid' => self::EMPLOYEE, 'typeId' => $this->typeId($type), 'startDate' => $from, 'endDate' => $to,
			'workingDays' => $days, 'replacementUid' => self::OTHER,
		]);
		// Started last year, so it counts there
		$record('sick', ($year - 1) . '-12-30', $year . '-01-02', 2);
		$record('sick', $year . '-01-05', $year . '-01-06', 2);
		$record('annual', ($year + 1) . '-03-01', ($year + 1) . '-03-05', 5);
		$next = $record('annual', ($year + 1) . '-02-01', ($year + 1) . '-02-02', 2);

		$profile = Server::get(ProfileService::class)->get(self::MANAGER, self::EMPLOYEE);

		self::assertSame(['year' => $year, 'days' => 2.0, 'times' => 1], $profile['sick']);
		self::assertSame($next->getId(), $profile['nextLeave']['id']);
	}

	public function testTheHistoryShowsChangesToHrAndThePersonOnly(): void {
		$this->service->saveContract(self::EMPLOYEE, null, null, [['from' => '2026-07-01', 'weekdays' => [1, 2, 3, 4], 'percent' => 80]], self::HR);
		// The same contract again changes nothing
		$this->service->saveContract(self::EMPLOYEE, null, null, [['from' => '2026-07-01', 'weekdays' => [1, 2, 3, 4], 'percent' => 80]], self::HR);
		$this->configController(self::EMPLOYEE)->updatePersonal(['holiday_country' => 'zz', 'holiday_region' => 't1']);

		$profiles = Server::get(ProfileService::class);
		$history = $profiles->get(self::HR, self::EMPLOYEE)['history'];
		self::assertSame(['region', 'contract'], array_column($history, 'field'));
		self::assertSame(['', 'ZZ-T1'], [$history[0]['before'], $history[0]['after']]);
		self::assertSame(self::EMPLOYEE, $history[0]['actor']);
		self::assertSame([], $history[1]['before']['periods']);
		self::assertSame(80, $history[1]['after']['periods'][0]['percent']);
		self::assertSame(self::HR, $history[1]['actor']);

		self::assertCount(2, $profiles->get(self::EMPLOYEE, self::EMPLOYEE)['history']);
		self::assertNull($profiles->get(self::MANAGER, self::EMPLOYEE)['history']);
	}

	public function testPeopleNoLongerSetTheirOwnWeekdays(): void {
		$this->configController(self::EMPLOYEE)->updatePersonal(['work_weekdays' => '1']);

		self::assertSame(ConfigService::DEFAULT_WORK_WEEKDAYS, Server::get(PersonalDefaultsService::class)->resolve(self::EMPLOYEE)['workWeekdays']);
	}

	public function testDeletingTheUserRemovesTheirContract(): void {
		$this->service->saveContract(self::EMPLOYEE, '2024-03-01', null, [['from' => '2024-03-01', 'weekdays' => [1, 2, 3], 'percent' => 60]], self::HR);

		Server::get(IUserManager::class)->get(self::EMPLOYEE)->delete();

		self::assertSame([], Server::get(ContractPeriodMapper::class)->findForEmployee(self::EMPLOYEE));
		self::assertSame([], $this->service->history(self::EMPLOYEE));
		self::assertNull($this->service->getContract(self::EMPLOYEE)['start']);
	}

	public function testTheAllowanceFollowsTheContract(): void {
		$balances = Server::get(BalanceService::class);
		$annual = $this->typeId('annual');
		$entitlement = $balances->ensureEntitlement(self::EMPLOYEE, 2026, $annual);
		Server::get(EntitlementService::class)->update(self::HR, $entitlement->getId(), [
			'baseDays' => 30.0, 'carryOverDays' => 3.0, 'adjustmentDelta' => 2.0, 'adjustmentNote' => 'itest moving day',
		]);
		self::assertSame(35.0, $this->annualRow($balances->getBalance(self::EMPLOYEE, 2026))['entitlement']);

		// Joins on 16 March, four days a week from July: 30 x (3 + 6 x 0.8) / 12 = 19.5, half a day up
		$this->service->saveContract(self::EMPLOYEE, '2026-03-16', null, [
			['from' => '2026-03-16', 'weekdays' => [1, 2, 3, 4, 5], 'percent' => 100],
			['from' => '2026-07-01', 'weekdays' => [1, 2, 3, 4], 'percent' => 80],
		], self::HR);

		$row = $this->annualRow($balances->getBalance(self::EMPLOYEE, 2026));
		self::assertSame(30.0, $row['baseDays']);
		self::assertSame(20.0, $row['contract']['days']);
		self::assertSame(25.0, $row['entitlement']);
		self::assertSame([[4, 6, 5], [7, 12, 4]], array_map(static fn (array $s): array => [$s['from'], $s['to'], $s['weekdays']], $row['contract']['segments']));

		$batch = $balances->getBalancesForEmployees([self::EMPLOYEE, self::OTHER], 2026);
		self::assertSame(25.0, $this->annualRow(['balances' => $batch[self::EMPLOYEE]])['entitlement']);
		self::assertNull($this->annualRow(['balances' => $batch[self::OTHER]])['contract']);
	}

	/**
	 * @param array{balances: list<array<string, mixed>>} $balance
	 * @return array<string, mixed>
	 */
	private function annualRow(array $balance): array {
		foreach ($balance['balances'] as $row) {
			if ($row['typeKey'] === 'annual') {
				return $row;
			}
		}
		self::fail('No annual leave row');
	}

	private function typeId(string $key): int {
		foreach (Server::get(LeaveTypeMapper::class)->findAll() as $type) {
			if ($type->getKey() === $key) {
				return $type->getId();
			}
		}
		self::fail('No leave type ' . $key);
	}

	private function profileController(string $uid): ProfileController {
		Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
		return new ProfileController('absence', Server::get(IRequest::class), $uid, Server::get(ProfileService::class), $this->service,
			Server::get(PermissionService::class), Server::get(LoggerInterface::class), Server::get(IFactory::class)->get('absence'));
	}

	private function configController(string $uid): ConfigController {
		Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
		return new ConfigController('absence', Server::get(IRequest::class), $uid, Server::get(ConfigService::class),
			Server::get(PersonalDefaultsService::class), $this->service, Server::get(SessionService::class), Server::get(IFactory::class)->get('absence'));
	}
}
