<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OCA\Absence\Controller\ImportController;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Db\RequestEventMapper;
use OCA\Absence\Event\LeaveRequestChangedEvent;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\LeaveImportService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\RequestService;
use OCP\Activity\IConsumer;
use OCP\Activity\IEvent;
use OCP\Activity\IManager as IActivityManager;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IApp;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Server;
use OCP\User\Backend\ABackend;
use PHPUnit\Framework\TestCase;

/**
 * The leave import (§6.5) against a real database and the real services:
 * all-or-nothing, user resolution, duplicates, balances, permissions, and the
 * absence of every side effect a normal booking has.
 */
class LeaveImportTest extends TestCase {
	private const HR = 'itest-li-hr';
	private const ROBIN = 'itest-li-robin';
	private const SAM = 'itest-li-sam';
	private const GUEST = 'itest-li-guest';
	private const ROBIN_EMAIL = 'robin.sample@example.com';

	private LeaveImportService $service;
	private LeaveRequestMapper $requests;
	private IUserManager $users;
	private ?GuestBackend $guests = null;
	private bool $createdHrGroup = false;
	/** @var list<string> */
	private array $changeEvents = [];
	private ?\Closure $changeListener = null;

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(LeaveImportService::class);
		$this->requests = Server::get(LeaveRequestMapper::class);
		$this->users = Server::get(IUserManager::class);

		foreach ([self::HR => 'Hana Records', self::ROBIN => 'Robin Sample', self::SAM => 'Sam Example'] as $uid => $name) {
			$user = $this->users->get($uid) ?? $this->users->createUser($uid, 'itest-' . bin2hex(random_bytes(8)));
			self::assertNotFalse($user);
			$user->setDisplayName($name);
		}
		$this->users->get(self::ROBIN)?->setSystemEMailAddress(self::ROBIN_EMAIL);

		$groups = Server::get(IGroupManager::class);
		$hrGroup = Server::get(ConfigService::class)->getHrGroup();
		if (!$groups->groupExists($hrGroup)) {
			$groups->createGroup($hrGroup);
			$this->createdHrGroup = true;
		}
		$groups->get($hrGroup)?->addUser($this->users->get(self::HR));

		$this->guests = new GuestBackend([self::GUEST]);
		$this->users->registerBackend($this->guests);

		SpyNotificationApp::$received = [];
		SpyActivityConsumer::$received = [];
		$this->changeEvents = [];
		$this->changeListener = function (LeaveRequestChangedEvent $event): void {
			$this->changeEvents[] = $event->getAction();
		};
		Server::get(IEventDispatcher::class)->addListener(LeaveRequestChangedEvent::class, $this->changeListener);
	}

	protected function tearDown(): void {
		Server::get(IEventDispatcher::class)->removeListener(LeaveRequestChangedEvent::class, $this->changeListener);
		$events = Server::get(RequestEventMapper::class);
		foreach ([self::HR, self::ROBIN, self::SAM] as $uid) {
			foreach ($this->requests->findAllForEmployee($uid) as $request) {
				$events->deleteForRequest((int)$request->getId());
				$this->requests->delete($request);
			}
			$this->users->get($uid)?->delete();
		}
		if ($this->guests !== null) {
			$this->users->removeBackend($this->guests);
		}
		if ($this->createdHrGroup) {
			Server::get(IGroupManager::class)->get(Server::get(ConfigService::class)->getHrGroup())?->delete();
		}
		parent::tearDown();
	}

	/**
	 * @param list<array<string, mixed>> $entries
	 */
	private static function json(array $entries): string {
		return json_encode($entries, JSON_THROW_ON_ERROR);
	}

	/** @return list<array<string, mixed>> */
	private static function sampleEntries(): array {
		return [
			['user' => self::ROBIN, 'from' => '2025-03-03', 'to' => '2025-03-07', 'workingDays' => 5, 'note' => 'Ski trip'],
			['user' => self::ROBIN, 'type' => 'annual', 'from' => '2025-08-11', 'to' => '2025-08-12', 'workingDays' => 2],
			['user' => self::SAM, 'type' => 'sick', 'from' => '2025-05-06', 'to' => '2025-05-06', 'workingDays' => 1],
		];
	}

	private static function typeId(string $key): int {
		foreach (Server::get(LeaveTypeMapper::class)->findAll() as $type) {
			if ($type->getKey() === $key) {
				return (int)$type->getId();
			}
		}
		self::fail("No leave type '$key'");
	}

	private function countFor(string $uid): int {
		return count($this->requests->findAllForEmployee($uid));
	}

	public function testImportsApprovedLeaveWithHistory(): void {
		$result = $this->service->import(self::json(self::sampleEntries()), self::HR);

		self::assertSame(3, $result['entries']);
		self::assertSame([], $result['errors']);
		$robin = $this->requests->findAllForEmployee(self::ROBIN);
		self::assertCount(2, $robin);
		foreach ($robin as $request) {
			self::assertSame(LeaveRequest::STATUS_APPROVED, $request->getStatus());
			self::assertSame(self::HR, $request->getDecidedBy());
			self::assertNull($request->getCalendarEventUri());
			$history = Server::get(RequestEventMapper::class)->findForRequest((int)$request->getId());
			self::assertCount(1, $history);
			self::assertSame('request_imported', $history[0]->getEventType());
			self::assertSame(self::HR, $history[0]->getActorUid());
		}
		$march = array_values(array_filter($robin, static fn (LeaveRequest $r): bool => $r->getStartDate() === '2025-03-03'))[0];
		self::assertSame('Ski trip', $march->getReason());
	}

	public function testOneInvalidEntryWritesNothing(): void {
		$entries = self::sampleEntries();
		$entries[] = ['user' => self::SAM, 'from' => '2025-06-10', 'to' => '2025-06-09', 'workingDays' => 1];

		$preview = $this->service->preview(self::json($entries));
		self::assertCount(1, $preview['errors']);
		self::assertSame(4, $preview['errors'][0]['entry']);
		self::assertSame(self::SAM, $preview['errors'][0]['user']);

		try {
			$this->service->import(self::json($entries), self::HR);
			self::fail('An import with an invalid entry must be refused');
		} catch (ValidationException) {
		}
		self::assertSame(0, $this->countFor(self::ROBIN));
		self::assertSame(0, $this->countFor(self::SAM));
	}

	public function testEveryInvalidEntryIsReported(): void {
		$preview = $this->service->preview(self::json([
			['user' => 'itest-li-nobody', 'from' => '2025-01-06', 'to' => '2025-01-06', 'workingDays' => 1],
			['user' => self::ROBIN, 'type' => 'itest-no-such-type', 'from' => '2025-01-06', 'to' => '2025-01-06', 'workingDays' => 1],
			['user' => self::ROBIN, 'from' => '2025-01-06', 'to' => '2025-01-07', 'workingDays' => 3],
			['user' => self::ROBIN, 'from' => '2025-01-06', 'to' => '2025-01-07', 'workingDays' => 0],
			['user' => self::ROBIN, 'from' => '6.1.2025', 'to' => '2025-01-07', 'workingDays' => 1],
		]));

		self::assertSame([1, 2, 3, 4, 5], array_column($preview['errors'], 'entry'));
		self::assertSame(0, $preview['entries']);
	}

	public function testEmailResolvesToTheUser(): void {
		$result = $this->service->import(self::json([
			['user' => self::ROBIN_EMAIL, 'from' => '2025-04-14', 'to' => '2025-04-16', 'workingDays' => 3],
		]), self::HR);

		self::assertSame(self::ROBIN, $result['people'][0]['uid']);
		self::assertSame('Robin Sample', $result['people'][0]['name']);
		self::assertSame(1, $this->countFor(self::ROBIN));
	}

	public function testGuestIsRejected(): void {
		self::assertNotNull($this->users->get(self::GUEST), 'the guest backend must be registered');

		$preview = $this->service->preview(self::json([
			['user' => self::GUEST, 'from' => '2025-04-14', 'to' => '2025-04-16', 'workingDays' => 3],
		]));

		self::assertCount(1, $preview['errors']);
		self::assertSame(self::GUEST, $preview['errors'][0]['user']);
		self::assertStringContainsString('not an employee', $preview['errors'][0]['message']);
	}

	public function testSecondImportSkipsDuplicates(): void {
		$json = self::json(self::sampleEntries());
		$this->service->import($json, self::HR);

		$again = $this->service->import($json, self::HR);

		self::assertSame(0, $again['entries']);
		self::assertSame(3, $again['skipped']);
		self::assertSame(2, $this->countFor(self::ROBIN));
		self::assertSame(1, $this->countFor(self::SAM));
	}

	public function testSameEntryTwiceInOneFileIsImportedOnce(): void {
		$entry = ['user' => self::ROBIN, 'from' => '2025-03-03', 'to' => '2025-03-07', 'workingDays' => 5];

		$result = $this->service->import(self::json([$entry, $entry]), self::HR);

		self::assertSame(1, $result['entries']);
		self::assertSame(1, $result['skipped']);
		self::assertSame(1, $this->countFor(self::ROBIN));
	}

	public function testImportedLeaveCountsAsUsed(): void {
		$this->service->import(self::json(self::sampleEntries()), self::HR);

		$annualId = self::typeId('annual');
		$row = array_values(array_filter(
			Server::get(BalanceService::class)->getBalance(self::ROBIN, 2025)['balances'],
			static fn (array $r): bool => $r['typeId'] === $annualId,
		))[0];
		self::assertEqualsWithDelta(7.0, $row['used'], 0.001);
		self::assertEqualsWithDelta(0.0, $row['pending'], 0.001);
	}

	public function testDryRunWritesNothing(): void {
		$preview = $this->service->preview(self::json(self::sampleEntries()));

		self::assertSame(3, $preview['entries']);
		self::assertEqualsWithDelta(8.0, $preview['days'], 0.001);
		self::assertSame(['Robin Sample', 'Sam Example'], array_column($preview['people'], 'name'));
		self::assertSame([2, 1], array_column($preview['people'], 'entries'));
		self::assertSame(0, $this->countFor(self::ROBIN));
		self::assertSame(0, $this->countFor(self::SAM));
	}

	public function testImportHasNoSideEffects(): void {
		Server::get(INotificationManager::class)->registerApp(SpyNotificationApp::class);
		$activity = Server::get(IActivityManager::class);
		$activity->registerConsumer(static fn () => new SpyActivityConsumer());

		$this->service->import(self::json(self::sampleEntries()), self::HR);

		self::assertSame([], SpyNotificationApp::$received, 'no notification');
		self::assertSame([], SpyActivityConsumer::$received, 'no activity');
		self::assertSame([], $this->changeEvents, 'no change event');

		// The spies do see a normal HR booking, so the silence above is the import's.
		$sickId = self::typeId('sick');
		Server::get(RequestService::class)->create(self::HR, [
			'typeId' => $sickId, 'employeeUid' => self::SAM, 'startDate' => '2025-09-01', 'endDate' => '2025-09-01',
			'workingDays' => 1, 'replacementUid' => self::ROBIN,
		]);
		self::assertNotSame([], SpyNotificationApp::$received);
		self::assertNotSame([], SpyActivityConsumer::$received);
		self::assertSame(['request_created'], $this->changeEvents);
	}

	public function testEndpointIsHrOnly(): void {
		$json = self::json(self::sampleEntries());

		foreach ([true, false] as $dryRun) {
			$response = $this->controller(self::ROBIN)->leave($json, $dryRun);
			self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		}
		self::assertSame(0, $this->countFor(self::ROBIN));

		$preview = $this->controller(self::HR)->leave($json, true);
		self::assertSame(Http::STATUS_OK, $preview->getStatus());
		self::assertSame(0, $this->countFor(self::ROBIN));

		$import = $this->controller(self::HR)->leave($json, false);
		self::assertSame(Http::STATUS_OK, $import->getStatus());
		self::assertSame(2, $this->countFor(self::ROBIN));
	}

	private function controller(string $uid): ImportController {
		return new ImportController(
			'absence',
			Server::get(IRequest::class),
			$uid,
			$this->service,
			Server::get(PermissionService::class),
			Server::get(IFactory::class)->get('absence'),
		);
	}
}

/**
 * Accounts reporting the Guests app's backend name, which is all
 * {@see \OCA\Absence\Service\EmployeeDirectory} looks at.
 */
class GuestBackend extends ABackend {
	/** @param list<string> $uids */
	public function __construct(
		private array $uids,
	) {
	}

	public function getBackendName(): string {
		return 'Guests';
	}

	public function deleteUser($uid): bool {
		return false;
	}

	public function getUsers($search = '', $limit = null, $offset = null): array {
		return $this->uids;
	}

	public function userExists($uid): bool {
		return in_array($uid, $this->uids, true);
	}

	public function getDisplayName($uid): string {
		return $uid;
	}

	public function getDisplayNames($search = '', $limit = null, $offset = null): array {
		return array_combine($this->uids, $this->uids);
	}

	public function hasUserListings(): bool {
		return true;
	}
}

class SpyNotificationApp implements IApp {
	/** @var list<INotification> */
	public static array $received = [];

	public function notify(INotification $notification): void {
		self::$received[] = $notification;
	}

	public function markProcessed(INotification $notification): void {
	}

	public function getCount(INotification $notification): int {
		return 0;
	}
}

class SpyActivityConsumer implements IConsumer {
	/** @var list<IEvent> */
	public static array $received = [];

	public function receive(IEvent $event): void {
		self::$received[] = $event;
	}
}
