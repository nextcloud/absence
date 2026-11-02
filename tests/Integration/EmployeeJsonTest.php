<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Command\ImportEmployees;
use OCA\Absence\ConfigLexicon;
use OCA\Absence\Controller\EmployeeController;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\EmployeeDirectory;
use OCA\Absence\Service\EmployeeJsonService;
use OCA\Absence\Service\EmployeeListService;
use OCA\Absence\Service\EmployeeService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\RegionReminderService;
use OCP\AppFramework\Http;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Test\Util\User\Dummy;

/**
 * Employees as one JSON list (§6.5), against a real server.
 */
class EmployeeJsonTest extends TestCase {
	private const HR = 'itest-json-hr';
	private const MANAGER = 'itest-json-mgr';
	private const ROBIN = 'itest-json-robin';
	private const SAM = 'itest-json-sam';
	private const GUEST = 'itest-json-guest';

	private EmployeeJsonService $service;
	/** @var list<CriticalActionPerformedEvent> */
	private array $audit = [];
	private ?Dummy $guests = null;

	protected function setUp(): void {
		parent::setUp();
		$users = Server::get(IUserManager::class);
		foreach ([self::HR, self::MANAGER, self::ROBIN, self::SAM] as $uid) {
			$users->createUser($uid, 'Itest-Password-' . strlen($uid) . 'json');
		}
		$users->get(self::ROBIN)->setDisplayName('Robin Sample');
		$users->get(self::ROBIN)->setSystemEMailAddress('robin.json@example.com');
		$users->get(self::SAM)->setDisplayName('Sam Example');
		$users->get(self::ROBIN)->setManagerUids([self::MANAGER]);
		$groups = Server::get(IGroupManager::class);
		($groups->get('hr') ?? $groups->createGroup('hr'))->addUser($users->get(self::HR));
		Server::get(ConfigService::class)->setPersonalValue(self::ROBIN, ConfigLexicon::KEY_HOLIDAY_COUNTRY, 'DE');
		Server::get(ConfigService::class)->setPersonalValue(self::ROBIN, ConfigLexicon::KEY_HOLIDAY_REGION, 'BY');
		Server::get(EmployeeService::class)->saveContract(self::ROBIN, '2024-03-01', null, [
			['from' => '2024-03-01', 'weekdays' => [1, 2, 3, 4, 5], 'percent' => 100],
		], self::HR);
		Server::get(IEventDispatcher::class)->addListener(CriticalActionPerformedEvent::class, function (CriticalActionPerformedEvent $event): void {
			$this->audit[] = $event;
		});
		$this->service = Server::get(EmployeeJsonService::class);
	}

	protected function tearDown(): void {
		foreach ([self::HR, self::MANAGER, self::ROBIN, self::SAM] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		if ($this->guests !== null) {
			Server::get(IUserManager::class)->removeBackend($this->guests);
		}
		parent::tearDown();
	}

	public function testTheExportImportsAgainWithoutChanges(): void {
		$entries = $this->service->export();
		$robin = $this->entry($entries, self::ROBIN);
		self::assertSame(['user' => self::ROBIN, 'name' => 'Robin Sample', 'region' => 'DE-BY', 'start' => '2024-03-01', 'end' => null,
			'contract' => [['from' => '2024-03-01', 'weekdays' => [1, 2, 3, 4, 5], 'percent' => 100]]], $robin);
		self::assertNull($this->entry($entries, self::SAM)['region']);

		$plan = $this->service->plan(EmployeeJsonService::format($entries));

		self::assertSame([], $plan['errors']);
		self::assertSame([], $plan['changes']);
		self::assertSame(count($entries), $plan['unchanged']);
	}

	public function testAnEntryReplacesRegionDatesAndContractWithHrAsTheAuthor(): void {
		$json = $this->json([
			['user' => 'robin.json@example.com', 'name' => 'ignored', 'region' => 'fr', 'start' => '2024-03-01', 'end' => '2027-06-30',
				'contract' => [
					['from' => '2024-03-01', 'weekdays' => [1, 2, 3, 4, 5], 'percent' => 100],
					['from' => '2027-01-01', 'weekdays' => [4, 1, 2, 3], 'percent' => 80],
				]],
		]);

		$plan = $this->service->plan($json);
		self::assertSame(self::ROBIN, $plan['changes'][0]['user']);
		self::assertSame(['region', 'end', 'contract'], array_column($plan['changes'][0]['fields'], 'field'));
		self::assertSame(['DE-BY', 'FR'], [$plan['changes'][0]['fields'][0]['before'], $plan['changes'][0]['fields'][0]['after']]);
		$historyBefore = count(Server::get(EmployeeService::class)->history(self::ROBIN));

		$this->service->import($json, self::HR);

		$employees = Server::get(EmployeeService::class);
		self::assertSame([1, 2, 3, 4], $employees->weekdaysOn(self::ROBIN, '2027-02-01'));
		self::assertSame('2027-06-30', $employees->getContract(self::ROBIN)['end']);
		self::assertSame(['holiday_country' => 'FR', 'holiday_region' => ''], Server::get(ConfigService::class)->getPersonalConfig(self::ROBIN));
		$history = array_slice($employees->history(self::ROBIN), 0, count($employees->history(self::ROBIN)) - $historyBefore);
		self::assertEqualsCanonicalizing(['contract', 'region'], array_column($history, 'field'));
		self::assertSame([self::HR, self::HR], array_column($history, 'actor'));
		$messages = array_map(static fn (CriticalActionPerformedEvent $e): array => [$e->getLogMessage(), $e->getParameters()], $this->audit);
		self::assertContains(['Contract of %s changed by %s', [self::ROBIN, self::HR]], $messages);
		self::assertContains(['Region code of %s changed from "%s" to "%s" by %s', [self::ROBIN, 'DE-BY', 'FR', self::HR]], $messages);
	}

	public function testANumericUserIdImports(): void {
		$users = Server::get(IUserManager::class);
		$users->createUser('90417', 'Itest-Password-numeric-json');
		try {
			$this->service->import($this->json([
				['user' => '90417', 'region' => 'FR', 'start' => '2026-01-01', 'end' => null, 'contract' => []],
			]), self::HR);

			self::assertSame('2026-01-01', Server::get(EmployeeService::class)->getContract('90417')['start']);
		} finally {
			$users->get('90417')?->delete();
		}
	}

	public function testNothingIsWrittenWhileOneEntryIsWrong(): void {
		$json = $this->json([
			['user' => self::SAM, 'region' => 'DE_BY', 'start' => null, 'end' => null, 'contract' => []],
			['user' => 'itest-json-nobody', 'region' => null, 'start' => null, 'end' => null, 'contract' => []],
		]);

		$plan = $this->service->plan($json);
		self::assertSame([], $plan['changes']);
		self::assertSame([['line' => 9, 'entry' => 2, 'message' => 'itest-json-nobody is not a user']], $plan['errors']);

		try {
			$this->service->import($json, self::HR);
			self::fail('The import went through');
		} catch (ValidationException) {
		}
		self::assertSame('', Server::get(ConfigService::class)->getPersonalConfig(self::SAM)['holiday_country']);

		// The same list without the wrong entry writes, reading DE_BY as DE-BY
		$this->service->import($this->json([['user' => self::SAM, 'region' => 'DE_BY', 'start' => null, 'end' => null, 'contract' => []]]), self::HR);
		self::assertSame(['holiday_country' => 'DE', 'holiday_region' => 'BY'], Server::get(ConfigService::class)->getPersonalConfig(self::SAM));
	}

	public function testDuplicatesAndBadValuesAreReportedWithTheirLine(): void {
		$entry = ['region' => null, 'start' => null, 'end' => null, 'contract' => []];
		$json = $this->json([
			['user' => self::ROBIN, ...$entry],
			['user' => 'robin.json@example.com', ...$entry],
			['user' => self::SAM, ...$entry, 'contract' => [
				['from' => '2026-07-01', 'weekdays' => [1, 2, 3, 4], 'percent' => 80],
				['from' => '2026-07-01', 'weekdays' => [1, 2, 3, 4, 5], 'percent' => 100],
			]],
			['user' => self::MANAGER, ...$entry, 'region' => '*'],
			['user' => self::HR, 'region' => null, 'start' => '2026-02-30', 'end' => null, 'contract' => []],
			['user' => self::HR],
		]);

		$errors = $this->service->plan($json)['errors'];

		self::assertSame([2, 3, 4, 5, 6], array_column($errors, 'entry'));
		// Each entry opens on the line before its "user"
		$lines = explode("\n", $json);
		$opening = static fn (int $nth): int => array_keys(array_filter($lines, static fn (string $line): bool => str_contains($line, '"user"')))[$nth - 1];
		self::assertSame(array_map($opening, [2, 3, 4, 5, 6]), array_column($errors, 'line'));
		self::assertSame('robin.json@example.com is already in entry 1', $errors[0]['message']);
		self::assertSame('Sam Example: Two changes start on 2026-07-01', $errors[1]['message']);
		self::assertStringStartsWith(self::MANAGER . ': * is for holidays everywhere', $errors[2]['message']);
		self::assertSame(self::HR . ': Dates are written YYYY-MM-DD', $errors[3]['message']);
		self::assertStringStartsWith(self::HR . ': "region" is missing', $errors[4]['message']);
	}

	public function testGuestsAreNotEmployees(): void {
		if (!class_exists(Dummy::class)) {
			self::markTestSkipped('The server test helpers are not installed.');
		}
		$this->guests = new class extends Dummy {
			#[\Override]
			public function getBackendName(): string {
				return 'Guests';
			}
		};
		$this->guests->createUser(self::GUEST, 'Itest-Password-guest');
		Server::get(IUserManager::class)->registerBackend($this->guests);

		$errors = $this->service->plan($this->json([['user' => self::GUEST, 'region' => 'DE', 'start' => null, 'end' => null, 'contract' => []]]))['errors'];

		self::assertSame(self::GUEST . ' is not an employee', $errors[0]['message']);
		self::assertNotContains(self::GUEST, array_column($this->service->export(), 'user'));
	}

	public function testOnlyHrExportsAndImports(): void {
		$json = $this->json([['user' => self::SAM, 'region' => 'FR', 'start' => null, 'end' => null, 'contract' => []]]);
		foreach ([self::SAM, self::MANAGER] as $uid) {
			self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($uid)->exportJson()->getStatus(), $uid);
			self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($uid)->importJson($json, false)->getStatus(), $uid);
		}
		self::assertSame('', Server::get(ConfigService::class)->getPersonalConfig(self::SAM)['holiday_country']);

		self::assertSame(Http::STATUS_OK, $this->controller(self::HR)->exportJson()->getStatus());
		$response = $this->controller(self::HR)->importJson($json, false);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('FR', Server::get(ConfigService::class)->getPersonalConfig(self::SAM)['holiday_country']);
	}

	public function testOccDryRunWritesNothing(): void {
		$file = tempnam(sys_get_temp_dir(), 'itest-employees');
		file_put_contents($file, $this->json([['user' => self::SAM, 'region' => 'CZ', 'start' => '2026-09-01', 'end' => null, 'contract' => []]]));
		$tester = new CommandTester(Server::get(ImportEmployees::class));

		self::assertSame(0, $tester->execute(['file' => $file, '--dry-run' => true]));
		self::assertStringContainsString('Dry run: 1 employees would change', $tester->getDisplay());
		self::assertSame('', Server::get(ConfigService::class)->getPersonalConfig(self::SAM)['holiday_country']);
		self::assertNull(Server::get(EmployeeService::class)->getContract(self::SAM)['start']);

		self::assertSame(0, $tester->execute(['file' => $file]));
		unlink($file);
		self::assertSame('CZ', Server::get(ConfigService::class)->getPersonalConfig(self::SAM)['holiday_country']);
		self::assertSame([null, null], array_column(Server::get(EmployeeService::class)->history(self::SAM), 'actor'));
	}

	/**
	 * @param list<array<string, mixed>> $entries
	 * @return array<string, mixed>
	 */
	private function entry(array $entries, string $uid): array {
		foreach ($entries as $entry) {
			if ($entry['user'] === $uid) {
				return $entry;
			}
		}
		self::fail("$uid is not in the export");
	}

	/**
	 * @param list<array<string, mixed>> $entries
	 */
	private function json(array $entries): string {
		return json_encode($entries, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
	}

	private function controller(string $uid): EmployeeController {
		Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get($uid));
		return new EmployeeController('absence', Server::get(IRequest::class), $uid, Server::get(IUserManager::class),
			Server::get(IGroupManager::class), Server::get(ISearch::class), Server::get(EmployeeDirectory::class),
			Server::get(EmployeeListService::class), $this->service, Server::get(RegionReminderService::class), Server::get(PermissionService::class),
			Server::get(LoggerInterface::class), Server::get(IFactory::class)->get('absence'));
	}
}
