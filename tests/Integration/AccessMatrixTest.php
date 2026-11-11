<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Integration;

use OC\AppFramework\DependencyInjection\DIContainer;
use OC\AppFramework\Http\Dispatcher;
use OC\AppFramework\Http\Request;
use OC\Security\CSRF\CsrfTokenManager;
use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\Entitlement;
use OCA\Absence\Db\EntitlementMapper;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\AttachmentService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\HolidayService;
use OCP\AppFramework\Http\Attribute\Route;
use OCP\AppFramework\Http\Response;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every route, called as every kind of user through the server's own
 * dispatcher and middleware, as a browser request would be: who gets in and
 * who is turned away. A route missing from the matrix fails the test.
 */
class AccessMatrixTest extends TestCase {
	private const PREFIX = 'itest-access-';
	private const STAFF_GROUP = 'itest-access-staff';
	private const HR_GROUP = 'itest-access-hr';
	private const TYPE_KEY = 'itest_access';
	private const HOLIDAY_REGION = 'ZZ-ACC';

	/** Not logged in */
	private const ANON = 'anon';
	/** Logged in, outside the employees group */
	private const OUTSIDER = 'outsider';
	/** An employee with no tie to the owner */
	private const COLLEAGUE = 'colleague';
	/** The employee whose request, balance and profile are at stake */
	private const OWNER = 'owner';
	/** The owner's manager */
	private const MANAGER = 'manager';
	private const HR = 'hr';
	/** A Nextcloud admin, neither HR nor an employee */
	private const ADMIN = 'admin';
	private const ROLES = [self::ANON, self::OUTSIDER, self::COLLEAGUE, self::OWNER, self::MANAGER, self::HR, self::ADMIN];
	private const LOGGED_IN = [self::OUTSIDER, self::COLLEAGUE, self::OWNER, self::MANAGER, self::HR, self::ADMIN];

	/** @var array<string,mixed> */
	private array $snapshot = [];
	/** @var list<string> groups the settings page may create, removed again if they were not there */
	private array $missingGroups = [];
	/** @var array<string,int|string|array<string,mixed>> placeholder => fixture */
	private array $ids = [];
	/** The file attachment#create uploads */
	private string $upload = '';

	/**
	 * route name => [allowed roles, parameters, how the others are turned
	 * away]. Placeholders in braces are the fixtures' ids. Allowed roles must
	 * not get 401 or 403; the others get 403 (401 when not logged in) before
	 * anything is read or written, or, for a list, an empty one.
	 *
	 * @return array<string, array{0: list<string>, 1: array<string,mixed>, 2?: 'empty'}>
	 */
	private static function matrix(): array {
		$hr = [self::HR];
		$viewers = [self::OWNER, self::MANAGER, self::HR];
		$from = ['from' => '2027-03-01', 'to' => '2027-03-31'];
		return [
			'page#index' => [self::LOGGED_IN, []],
			'config#session' => [self::LOGGED_IN, []],
			'config#personal' => [self::LOGGED_IN, []],
			'config#updatePersonal' => [self::LOGGED_IN, ['values' => []]],

			'request#index' => [self::LOGGED_IN, []],
			'request#show' => [$viewers, ['id' => '{request}']],
			'request#create' => [self::LOGGED_IN, ['typeId' => '{type}', 'startDate' => '2027-03-01', 'endDate' => '2027-03-02']],
			'request#update' => [[self::OWNER, self::HR], ['id' => '{request}', 'reason' => 'itest']],
			'request#cancel' => [[self::OWNER, self::HR], ['id' => '{request}']],
			'request#approve' => [[self::MANAGER, self::HR], ['id' => '{request}']],
			'request#reject' => [[self::MANAGER, self::HR], ['id' => '{request}', 'comment' => 'itest']],
			'request#addComment' => [$viewers, ['id' => '{request}', 'body' => 'itest']],

			'attachment#index' => [[self::OWNER, self::HR], ['id' => '{request}'], 'empty'],
			'attachment#create' => [[self::OWNER, self::HR], ['id' => '{request}', 'file' => '{upload}']],
			'attachment#download' => [[self::OWNER, self::HR], ['id' => '{attachment}']],
			'attachment#destroy' => [[self::OWNER, self::HR], ['id' => '{attachment}']],

			'employee#index' => [$hr, []],
			'employee#search' => [self::LOGGED_IN, ['search' => 'itest']],
			'employee#exportJson' => [$hr, []],
			'employee#importJson' => [$hr, ['json' => '[]', 'dryRun' => true]],
			'employee#remind' => [$hr, ['dryRun' => true]],
			'employee#groups' => [$hr, []],

			'balance#mine' => [self::LOGGED_IN, []],
			'balance#team' => [self::LOGGED_IN, []],
			'balance#forEmployee' => [$viewers, ['uid' => '{owner}']],
			'entitlement#index' => [$hr, ['employeeUid' => '{owner}']],
			'entitlement#create' => [$hr, ['employeeUid' => '{owner}', 'year' => 2028, 'typeId' => '{type}', 'baseDays' => 1.0]],
			'entitlement#update' => [$hr, ['id' => '{entitlement}', 'adjustmentDelta' => 1.0, 'adjustmentNote' => 'itest']],
			'entitlement#bulk' => [$hr, ['year' => 2028, 'typeId' => '{type}', 'baseDays' => 1.0, 'group' => self::STAFF_GROUP]],
			// days nobody has: the calls change nothing
			'entitlement#onDefault' => [[self::HR, self::ADMIN], ['days' => 987.5]],
			'entitlement#applyDefault' => [[self::HR, self::ADMIN], ['from' => 987.5, 'to' => 986.5]],
			'entitlement#history' => [$hr, ['id' => '{entitlement}']],

			'coverage#index' => [self::LOGGED_IN, $from],
			'calendar#index' => [self::LOGGED_IN, $from],

			'leaveType#index' => [self::LOGGED_IN, []],
			'leaveType#create' => [$hr, ['key' => 'itest_access_new', 'label' => 'itest']],
			'leaveType#update' => [$hr, ['id' => '{type}', 'label' => 'itest']],
			'leaveType#destroy' => [$hr, ['id' => '{type}']],

			'holiday#index' => [self::LOGGED_IN, ['year' => 2027]],
			'holiday#applying' => [self::LOGGED_IN, ['code' => self::HOLIDAY_REGION] + $from],
			'holiday#regions' => [self::LOGGED_IN, []],
			'holiday#history' => [self::LOGGED_IN, []],
			'holiday#create' => [$hr, ['date' => '2027-05-05', 'region' => self::HOLIDAY_REGION, 'label' => 'itest']],
			'holiday#import' => [$hr, ['json' => '[]', 'dryRun' => true]],
			'holiday#update' => [$hr, ['id' => '{holiday}', 'date' => '2027-05-06', 'region' => self::HOLIDAY_REGION, 'label' => 'itest']],
			'holiday#destroy' => [$hr, ['id' => '{holiday}']],

			'report#balances' => [$hr, []],
			'report#trends' => [$hr, $from],
			'report#sickLeave' => [$hr, []],
			'report#insights' => [$hr, []],
			'export#requests' => [$hr, $from],
			'export#balances' => [$hr, []],
			'import#leave' => [$hr, ['json' => '[]', 'dryRun' => true]],

			'profile#show' => [$viewers, ['uid' => '{owner}']],
			'profile#updateContract' => [$hr, ['uid' => '{owner}', 'start' => '2020-01-01']],

			'settings#show' => [[self::HR, self::ADMIN], []],
			'settings#update' => [[self::HR, self::ADMIN], ['values' => [ConfigLexicon::KEY_NOTICE_PERIOD => 3]]],
			'settings#employeesPreview' => [[self::HR, self::ADMIN], ['group' => self::STAFF_GROUP]],
			'settings#createGroup' => [[self::ADMIN], ['kind' => 'employees']],
		];
	}

	/** @return array<string, array{string}> */
	public static function routes(): array {
		$names = array_keys(self::matrix());
		return array_combine($names, array_map(fn (string $name) => [$name], $names));
	}

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$users = Server::get(IUserManager::class);
		$groups = Server::get(IGroupManager::class);
		$staff = $groups->createGroup(self::STAFF_GROUP);
		$groups->createGroup(self::HR_GROUP)->addUser($users->createUser(self::PREFIX . self::HR, 'Itest-Password-hr'));
		$staff->addUser($users->get(self::PREFIX . self::HR));
		foreach ([self::COLLEAGUE, self::OWNER, self::MANAGER] as $role) {
			$staff->addUser($users->createUser(self::PREFIX . $role, 'Itest-Password-' . $role));
		}
		$users->createUser(self::PREFIX . self::OUTSIDER, 'Itest-Password-outsider');
		$groups->get('admin')->addUser($users->createUser(self::PREFIX . self::ADMIN, 'Itest-Password-admin'));
		$users->get(self::PREFIX . self::OWNER)->setManagerUids([self::PREFIX . self::MANAGER]);
	}

	public static function tearDownAfterClass(): void {
		$users = Server::get(IUserManager::class);
		foreach (self::LOGGED_IN as $role) {
			$users->get(self::PREFIX . $role)?->delete();
		}
		$groups = Server::get(IGroupManager::class);
		foreach ([self::STAFF_GROUP, self::HR_GROUP] as $gid) {
			$groups->get($gid)?->delete();
		}
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->snapshot = Server::get(ConfigService::class)->getAdminConfig();
		$groups = Server::get(IGroupManager::class);
		$this->missingGroups = array_values(array_filter(['hr', 'employees'], fn (string $gid) => !$groups->groupExists($gid)));

		$appConfig = Server::get(IAppConfig::class);
		$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_EMPLOYEES_GROUP, self::STAFF_GROUP);
		$appConfig->setValueString(ConfigService::APP_ID, ConfigLexicon::KEY_HR_GROUP, self::HR_GROUP);
		$appConfig->setValueBool(ConfigService::APP_ID, ConfigLexicon::KEY_HOLIDAYS_EVERYONE, false);

		$this->createFixtures();
	}

	private function createFixtures(): void {
		$owner = self::PREFIX . self::OWNER;
		$now = new \DateTime();

		$type = new LeaveType();
		$type->setKey(self::TYPE_KEY);
		$type->setLabel('itest access');
		$type->setCountsAgainstBalance(false);
		$type = Server::get(LeaveTypeMapper::class)->insert($type);

		$request = new LeaveRequest();
		$request->setEmployeeUid($owner);
		$request->setManagerUid(self::PREFIX . self::MANAGER);
		$request->setTypeId($type->getId());
		$request->setStartDate('2027-04-05');
		$request->setEndDate('2027-04-06');
		$request->setWorkingDays(2.0);
		$request->setCreatedAt($now);
		$request->setUpdatedAt($now);
		$request = Server::get(LeaveRequestMapper::class)->insert($request);

		$entitlement = new Entitlement();
		$entitlement->setEmployeeUid($owner);
		$entitlement->setYear(2027);
		$entitlement->setTypeId($type->getId());
		$entitlement->setBaseDays(10.0);
		$entitlement->setCreatedAt($now);
		$entitlement->setUpdatedAt($now);
		$entitlement = Server::get(EntitlementMapper::class)->insert($entitlement);

		$this->upload = tempnam(sys_get_temp_dir(), 'itest-access');
		file_put_contents($this->upload, 'itest');
		$upload = ['name' => 'note.txt', 'type' => 'text/plain', 'tmp_name' => $this->upload, 'size' => 5, 'error' => \UPLOAD_ERR_OK];
		$attachment = Server::get(AttachmentService::class)->upload($owner, $request->getId(), $upload);

		$holiday = Server::get(HolidayService::class)->create('2027-01-06', self::HOLIDAY_REGION, 'itest access day');

		$this->ids = [
			'{owner}' => $owner,
			'{type}' => $type->getId(),
			'{request}' => $request->getId(),
			'{entitlement}' => $entitlement->getId(),
			'{attachment}' => $attachment->getId(),
			'{holiday}' => $holiday->getId(),
			'{upload}' => $upload,
		];
	}

	protected function tearDown(): void {
		Server::get(IUserSession::class)->setUser(null);
		$uids = array_map(fn (string $role) => self::PREFIX . $role, self::LOGGED_IN);
		$db = Server::get(IDBConnection::class);

		$qb = $db->getQueryBuilder();
		$qb->select('id')->from('absence_requests')
			->where($qb->expr()->in('employee_uid', $qb->createNamedParameter($uids, IQueryBuilder::PARAM_STR_ARRAY)));
		$requestIds = array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		Server::get(AttachmentService::class)->purgeForRequests($requestIds);
		foreach (['absence_comments', 'absence_request_events'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in('request_id', $qb->createNamedParameter($requestIds, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		}
		foreach (['absence_requests', 'absence_entitlements'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in('employee_uid', $qb->createNamedParameter($uids, IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('absence_leave_types')->where($qb->expr()->like('key', $qb->createNamedParameter(self::TYPE_KEY . '%')))->executeStatement();
		$qb = $db->getQueryBuilder();
		$qb->delete('absence_holidays')->where($qb->expr()->eq('region', $qb->createNamedParameter(self::HOLIDAY_REGION)))->executeStatement();

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
		$groups = Server::get(IGroupManager::class);
		foreach ($this->missingGroups as $gid) {
			$groups->get($gid)?->delete();
		}
		@unlink($this->upload);
		parent::tearDown();
	}

	/**
	 * A new route, in routes.php (plain or OCS) or as a controller attribute,
	 * fails here until the matrix says who may call it.
	 */
	public function testEveryRouteIsInTheMatrix(): void {
		$file = require __DIR__ . '/../../appinfo/routes.php';
		$routes = array_column([...$file['routes'] ?? [], ...$file['ocs'] ?? []], 'name');
		foreach (glob(__DIR__ . '/../../lib/Controller/*Controller.php') as $path) {
			$class = new \ReflectionClass('OCA\\Absence\\Controller\\' . basename($path, '.php'));
			foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF) !== []) {
					$routes[] = lcfirst(substr($class->getShortName(), 0, -strlen('Controller'))) . '#' . $method->getName();
				}
			}
		}
		$missing = array_diff($routes, array_keys(self::matrix()));
		self::assertSame([], array_values($missing), 'Add these routes to the access matrix, with who may call them');
		self::assertSame([], array_values(array_diff(array_keys(self::matrix()), $routes)), 'These matrix rows have no route any more');
	}

	#[DataProvider('routes')]
	public function testRoute(string $name): void {
		[$allowed, $params] = self::matrix()[$name];
		$turnedAway = self::matrix()[$name][2] ?? 'forbidden';
		$params = array_map(fn (mixed $value) => is_string($value) ? ($this->ids[$value] ?? $value) : $value, $params);

		// The turned-away first: an allowed call may delete what they are asked about
		$denied = array_values(array_diff(self::ROLES, $allowed));
		$got = [];
		foreach ([...$denied, ...$allowed] as $role) {
			$response = $this->call($role, $name, $params);
			$got[$role] = $response->getStatus();
			if ($turnedAway === 'empty' && $response->getStatus() === 200) {
				$got[$role] = $response->getData() === [] ? 'empty' : 'listed';
			}
		}

		$expected = [];
		foreach ($got as $role => $status) {
			$expected[$role] = match (true) {
				in_array($role, $allowed, true) => match ($turnedAway) {
					'empty' => 'listed',
					default => in_array($status, [401, 403], true) ? 'allowed' : $status,
				},
				$role === self::ANON => 401,
				$turnedAway === 'empty' => 'empty',
				default => 403,
			};
		}
		self::assertSame($expected, $got, $name);
	}

	/**
	 * One request through the dispatcher and its middleware, from a fresh
	 * container as in a new HTTP request.
	 *
	 * @param array<string,mixed> $params
	 */
	private function call(string $role, string $name, array $params): Response {
		$route = null;
		foreach ((require __DIR__ . '/../../appinfo/routes.php')['routes'] as $candidate) {
			if ($candidate['name'] === $name) {
				$route = $candidate;
			}
		}
		self::assertNotNull($route, $name);

		$session = Server::get(IUserSession::class);
		$session->setUser($role === self::ANON ? null : Server::get(IUserManager::class)->get(self::PREFIX . $role));

		preg_match_all('/{(\w+)}/', $route['url'], $placeholders);
		$urlParams = array_intersect_key($params, array_flip($placeholders[1]));
		$files = array_intersect_key($params, ['file' => true]);
		$verb = $route['verb'];
		$request = new Request([
			'server' => [
				'REQUEST_METHOD' => $verb,
				'REQUEST_URI' => '/index.php/apps/absence' . $route['url'],
				'SCRIPT_NAME' => '/index.php',
				'HTTP_ACCEPT' => 'application/json',
				'HTTP_OCS_APIREQUEST' => 'true',
			],
			'method' => $verb,
			'urlParams' => $urlParams,
			'files' => $files,
			($verb === 'GET' ? 'get' : 'post') => array_diff_key($params, $urlParams, $files),
		], Server::get(IRequestId::class), Server::get(IConfig::class), Server::get(CsrfTokenManager::class), 'php://memory');

		[$controllerName, $method] = explode('#', $name);
		$class = 'OCA\\Absence\\Controller\\' . ucfirst($controllerName) . 'Controller';

		// A new container registers itself as the app's: put the app's own back.
		// Rendering the page reads the server's request, so it is swapped too.
		$server = \OC::$server;
		$registered = $server->getRegisteredAppContainer('absence');
		$serverRequest = $server->get(IRequest::class);
		$server->registerService(IRequest::class, fn () => $request);
		$container = new DIContainer('absence');
		$container->registerService(IRequest::class, fn () => $request);
		try {
			return $container->get(Dispatcher::class)->dispatch($container->get($class), $method)[4];
		} finally {
			$server->registerService(IRequest::class, fn () => $serverRequest);
			$server->registerAppContainer('absence', $registered);
		}
	}
}
