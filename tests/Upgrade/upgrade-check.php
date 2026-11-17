<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Checks that an upgrade of Absence keeps what people entered.
 *
 *   php upgrade-check.php <server root> seed               invented data, on the old version
 *   php upgrade-check.php <server root> snapshot <file>    write what is stored
 *   php upgrade-check.php <server root> verify <file>      after `occ upgrade`
 *
 * `snapshot` and `verify` read whatever the instance holds, so they also serve
 * to rehearse the upgrade on a copy of a real database. A snapshot of real
 * data is personal data: keep it out of the repository.
 *
 * `seed` writes the rows the way the old version stored them: 1.0.12 (commit
 * 0d84f4d), the version HR used before the betas, or 1.0.0-beta.1. Every
 * person and value in it is invented.
 *
 * Only OCP and plain queries are used to read and write, so the same file runs
 * against every version. The balances are asked from the app itself, to
 * compare what it shows before and after.
 */

use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

if ($argc < 3) {
	fwrite(STDERR, "usage: upgrade-check.php <server root> seed|snapshot <file>|verify <file>\n");
	exit(2);
}
[, $serverRoot, $mode] = $argv;
$file = $argv[3] ?? null;
require rtrim($serverRoot, '/') . '/lib/base.php';

const APP = 'absence';
const USERS = ['upgrade-alice', 'upgrade-bob', 'upgrade-carol', 'upgrade-dave', 'upgrade-hr'];

/** Weekdays people set for themselves on 1.0.12: sorted, short, unsorted and not weekdays at all */
const WEEKDAYS = [
	'upgrade-alice' => '1,2,3,4,5',
	'upgrade-bob' => '1,2,3,4',
	'upgrade-carol' => '5,1,3',
	'upgrade-dave' => 'every day',
];

$db = Server::get(IDBConnection::class);

if ($mode === 'seed') {
	seed($db);
} elseif ($mode === 'snapshot' && $file !== null) {
	file_put_contents($file, json_encode(before($db), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
} elseif ($mode === 'verify' && $file !== null) {
	exit(verify($db, json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)));
} else {
	exit(2);
}

function seed(IDBConnection $db): void {
	// 1.0.12 keeps the entitlement history under its long name and has no contracts yet
	$before1013 = !$db->tableExists('absence_ent_events');

	$users = Server::get(IUserManager::class);
	foreach (USERS as $uid) {
		$users->get($uid) ?? $users->createUser($uid, bin2hex(random_bytes(16)) . 'Aa1!');
	}
	$groups = Server::get(IGroupManager::class);
	// The HR group of the old default; the setting itself is never stored
	($groups->get('hr') ?? $groups->createGroup('hr'))->addUser($users->get('upgrade-hr'));
	foreach (['upgrade-alice', 'upgrade-bob', 'upgrade-carol'] as $uid) {
		$users->get($uid)->setManagerUids(['upgrade-dave']);
	}

	$config = Server::get(IUserConfig::class);
	if ($before1013) {
		foreach (WEEKDAYS as $uid => $stored) {
			$config->setValueString($uid, APP, 'work_weekdays', $stored);
		}
	} else {
		// The betas keep contracts: HR entered one for somebody
		insert($db, 'absence_employments', ['employee_uid' => 'upgrade-alice', 'start_date' => '2022-04-01', 'end_date' => null]);
		insert($db, 'absence_periods', ['employee_uid' => 'upgrade-alice', 'valid_from' => '2022-04-01', 'weekdays' => '1,2,3,4', 'percent' => [80, IQueryBuilder::PARAM_INT]]);
	}
	$config->setValueString('upgrade-alice', APP, 'holiday_country', 'DE');
	$config->setValueString('upgrade-alice', APP, 'holiday_region', 'BY');
	$config->setValueString('upgrade-bob', APP, 'holiday_country', 'FR');

	$appConfig = Server::get(IAppConfig::class);
	$appConfig->setValueFloat(APP, 'default_entitlement', 28.0);
	$appConfig->setValueInt(APP, 'max_concurrent_absences', 3);
	$appConfig->setValueString(APP, 'carryover_expiry', '03-31', lazy: true);

	$types = typeIds($db);
	insert($db, 'absence_leave_types', [
		'key' => 'training', 'label' => 'Training', 'color' => '#4271a6', 'icon' => '📚',
		'counts_against_balance' => [false, IQueryBuilder::PARAM_BOOL], 'requires_approval' => [true, IQueryBuilder::PARAM_BOOL],
		'requires_note' => [true, IQueryBuilder::PARAM_BOOL], 'requires_replacement' => [false, IQueryBuilder::PARAM_BOOL],
		'employee_requestable' => [true, IQueryBuilder::PARAM_BOOL], 'enabled' => [true, IQueryBuilder::PARAM_BOOL],
		'hr_only' => [false, IQueryBuilder::PARAM_BOOL], 'sort_order' => [20, IQueryBuilder::PARAM_INT],
	]);
	$update = $db->getQueryBuilder();
	$update->update('absence_leave_types')->set('enabled', $update->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
		->where($update->expr()->eq('key', $update->createNamedParameter('special')))->executeStatement();

	// A half day, a confidential absence, pending leave, leave across New Year and a cancelled request
	$requests = [
		['upgrade-alice', 'annual', '2026-02-16', '2026-02-20', '5', 'APPROVED', 'Ski week', 'upgrade-bob', 'upgrade-dave', false],
		['upgrade-alice', 'annual', '2026-08-03', '2026-08-04', '1.5', 'APPROVED', null, 'upgrade-bob', 'upgrade-dave', false],
		['upgrade-bob', 'sick', '2026-03-02', '2026-03-03', '2', 'APPROVED', null, null, 'upgrade-hr', true],
		['upgrade-carol', 'maternity', '2026-04-01', '2026-06-30', '60', 'APPROVED', 'Invented confidential note', null, 'upgrade-hr', false],
		['upgrade-bob', 'annual', '2026-12-21', '2026-12-23', '3', 'PENDING', null, 'upgrade-alice', null, false],
		['upgrade-carol', 'annual', '2025-12-29', '2026-01-02', '4', 'APPROVED', null, 'upgrade-alice', 'upgrade-dave', false],
		['upgrade-alice', 'unpaid', '2026-05-04', '2026-05-04', '0.5', 'CANCELLED', 'Invented reason', null, null, false],
	];
	$ids = [];
	foreach ($requests as [$uid, $type, $from, $to, $days, $status, $reason, $replacement, $decidedBy, $disability]) {
		$ids[] = insert($db, 'absence_requests', [
			'employee_uid' => $uid, 'manager_uid' => 'upgrade-dave', 'type_id' => [$types[$type], IQueryBuilder::PARAM_INT],
			// 1.0.12 keeps days in date columns, the betas as text
			'start_date' => $before1013 ? [new DateTime($from), IQueryBuilder::PARAM_DATE] : $from,
			'end_date' => $before1013 ? [new DateTime($to), IQueryBuilder::PARAM_DATE] : $to,
			'working_days' => $days, 'status' => $status, 'reason' => $reason, 'replacement_uid' => $replacement,
			'decided_by' => $decidedBy, 'decided_at' => [$decidedBy === null ? null : new DateTime('2026-01-10 09:00:00'), $decidedBy === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_DATETIME_MUTABLE],
			'escalated' => [false, IQueryBuilder::PARAM_BOOL], 'disability' => [$disability, IQueryBuilder::PARAM_BOOL],
			'created_at' => [new DateTime('2026-01-05 08:00:00'), IQueryBuilder::PARAM_DATETIME_MUTABLE],
			'updated_at' => [new DateTime('2026-01-10 09:00:00'), IQueryBuilder::PARAM_DATETIME_MUTABLE],
		]);
	}

	foreach ([
		[$ids[0], 'upgrade-dave', 'Enjoy the snow.'],
		[$ids[3], 'upgrade-hr', 'Invented HR-only remark.'],
		[$ids[4], 'upgrade-bob', 'Back on the 28th.'],
	] as [$request, $author, $body]) {
		insert($db, 'absence_comments', ['request_id' => [$request, IQueryBuilder::PARAM_INT], 'author_uid' => $author, 'body' => $body,
			'created_at' => [new DateTime('2026-01-06 10:00:00'), IQueryBuilder::PARAM_DATETIME_MUTABLE]]);
	}
	foreach ([[$ids[0], 'upgrade-alice', 'created'], [$ids[0], 'upgrade-dave', 'approved'], [$ids[3], 'upgrade-hr', 'created']] as [$request, $actor, $event]) {
		insert($db, 'absence_request_events', ['request_id' => [$request, IQueryBuilder::PARAM_INT], 'actor_uid' => $actor, 'event_type' => $event,
			'detail' => null, 'created_at' => [new DateTime('2026-01-05 08:00:00'), IQueryBuilder::PARAM_DATETIME_MUTABLE]]);
	}

	// A half-day allowance, a carry-over and an adjustment with its note
	$entitlements = [
		['upgrade-alice', 2026, '28', '5', '-1.5', 'Invented correction'],
		['upgrade-bob', 2026, '27.5', '0', '0', null],
		['upgrade-carol', 2025, '28', '0', '0', null],
		['upgrade-carol', 2026, '30', '3', '0', null],
	];
	foreach ($entitlements as [$uid, $year, $base, $carry, $adjustment, $note]) {
		$id = insert($db, 'absence_entitlements', [
			'employee_uid' => $uid, 'year' => [$year, IQueryBuilder::PARAM_INT], 'type_id' => [$types['annual'], IQueryBuilder::PARAM_INT],
			'base_days' => $base, 'carry_over_days' => $carry, 'manual_adjustment' => $adjustment, 'adjustment_note' => $note,
			'created_at' => [new DateTime('2026-01-02 08:00:00'), IQueryBuilder::PARAM_DATETIME_MUTABLE],
			'updated_at' => [new DateTime('2026-01-02 08:00:00'), IQueryBuilder::PARAM_DATETIME_MUTABLE],
		]);
		insert($db, $before1013 ? 'absence_entitlement_events' : 'absence_ent_events', [
			'entitlement_id' => [$id, IQueryBuilder::PARAM_INT], 'employee_uid' => $uid, 'actor_uid' => 'upgrade-hr',
			'field' => 'base_days', 'old_value' => '0', 'new_value' => $base, 'note' => $note,
			'created_at' => [new DateTime('2026-01-02 08:00:00'), IQueryBuilder::PARAM_DATETIME_MUTABLE],
		]);
	}

	$content = "%PDF-1.4\ninvented attachment for the upgrade check\n";
	$attachment = insert($db, 'absence_attachments', [
		'request_id' => [$ids[2], IQueryBuilder::PARAM_INT], 'uploader_uid' => 'upgrade-hr', 'name' => 'invented-note.pdf',
		'mime' => 'application/pdf', 'size' => [strlen($content), IQueryBuilder::PARAM_INT],
		'created_at' => [new DateTime('2026-03-04 08:00:00'), IQueryBuilder::PARAM_DATETIME_MUTABLE],
	]);
	attachmentFolder()->newFile((string)$attachment, $content);
	echo 'seeded the data of ' . ($before1013 ? '1.0.12' : 'a beta') . "\n";
}

/** @param array<string, mixed> $values a value, or [value, parameter type] */
function insert(IDBConnection $db, string $table, array $values): int {
	$qb = $db->getQueryBuilder();
	$qb->insert($table);
	foreach ($values as $column => $value) {
		[$value, $type] = is_array($value) ? $value : [$value, $value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR];
		$qb->setValue($column, $qb->createNamedParameter($value, $type));
	}
	$qb->executeStatement();
	return $qb->getLastInsertId();
}

/** @return array<string, int> leave type key => id */
function typeIds(IDBConnection $db): array {
	$result = $db->getQueryBuilder()->select('id', 'key')->from('absence_leave_types')->executeQuery();
	$ids = [];
	while ($row = $result->fetch()) {
		$ids[$row['key']] = (int)$row['id'];
	}
	$result->closeCursor();
	return $ids;
}

function attachmentFolder(): \OCP\Files\SimpleFS\ISimpleFolder {
	$appData = Server::get(IAppDataFactory::class)->get(APP);
	try {
		return $appData->getFolder('attachments');
	} catch (NotFoundException) {
		return $appData->newFolder('attachments');
	}
}

/** @return list<array<string, mixed>> */
function rows(IDBConnection $db, string $table, array $columns, string $order = 'id'): array {
	$qb = $db->getQueryBuilder();
	$result = $qb->select(...$columns)->from($table)->orderBy($order)->executeQuery();
	$rows = $result->fetchAll();
	$result->closeCursor();
	return $rows;
}

function flag(mixed $value): bool {
	return in_array($value, [true, 1, '1', 't', 'true'], true);
}

function day(mixed $value): ?string {
	return $value === null ? null : substr((string)$value, 0, 10);
}

function number(mixed $value): string {
	return rtrim(rtrim(sprintf('%.2F', (float)$value), '0'), '.');
}

/** The weekdays of a personal setting in order, or null when it does not list weekdays */
function weekdays(string $stored): ?string {
	if (preg_match('/^[1-7](,[1-7])*$/', $stored) !== 1) {
		return null;
	}
	$days = explode(',', $stored);
	if (count(array_unique($days)) !== count($days)) {
		return null;
	}
	sort($days);
	return implode(',', $days);
}

/**
 * Read from the table: the typed read of the server fails on an empty value,
 * which Oracle stores as NULL.
 *
 * @return array<string, string> uid => value, for everybody who stored one
 */
function personal(string $key): array {
	$qb = Server::get(IDBConnection::class)->getQueryBuilder();
	$qb->select('userid', 'configvalue')->from('preferences')
		->where($qb->expr()->eq('appid', $qb->createNamedParameter(APP)))
		->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)));
	$result = $qb->executeQuery();
	$values = [];
	while ($row = $result->fetch()) {
		$values[(string)$row['userid']] = (string)($row['configvalue'] ?? '');
	}
	$result->closeCursor();
	ksort($values);
	return $values;
}

/**
 * What the app itself shows as balance, for every person and year with an
 * entitlement or a request.
 *
 * @param list<array<string, mixed>> $requests
 * @param list<array<string, mixed>> $entitlements
 * @return array<string, array<string, array<string, string>>> "uid year" => type => figures
 */
function balances(array $requests, array $entitlements): array {
	Server::get(IAppManager::class)->loadApp(APP);
	/** @psalm-suppress UndefinedClass the app is not part of this script */
	$service = Server::get(\OCA\Absence\Service\BalanceService::class);
	$wanted = [];
	foreach ($requests as $request) {
		$wanted[$request['employee'] . ' ' . substr((string)$request['from'], 0, 4)] = true;
	}
	foreach ($entitlements as $entitlement) {
		$wanted[$entitlement['employee'] . ' ' . $entitlement['year']] = true;
	}
	ksort($wanted);
	$balances = [];
	foreach (array_keys($wanted) as $key) {
		[$uid, $year] = explode(' ', $key);
		foreach ($service->getBalance($uid, (int)$year)['balances'] as $row) {
			if ((int)$row['year'] !== (int)$year) {
				continue;
			}
			$balances[$key][$row['typeKey']] = [
				'entitlement' => number($row['entitlement']), 'used' => number($row['used']),
				'pending' => number($row['pending']), 'available' => number($row['available']),
			];
		}
		ksort($balances[$key]);
	}
	return $balances;
}

/**
 * What people entered, in a form that reads the same before and after the
 * upgrade: dates as `YYYY-MM-DD`, numbers without trailing zeros, types by key.
 *
 * @return array<string, mixed>
 */
function snapshot(IDBConnection $db): array {
	$types = array_flip(typeIds($db));
	$requests = array_map(static fn (array $r): array => [
		'id' => (int)$r['id'], 'employee' => $r['employee_uid'], 'manager' => $r['manager_uid'], 'type' => $types[(int)$r['type_id']] ?? null,
		'from' => day($r['start_date']), 'to' => day($r['end_date']), 'days' => number($r['working_days']), 'status' => $r['status'],
		'reason' => $r['reason'], 'replacement' => $r['replacement_uid'], 'decidedBy' => $r['decided_by'], 'disability' => flag($r['disability']),
	], rows($db, 'absence_requests', ['id', 'employee_uid', 'manager_uid', 'type_id', 'start_date', 'end_date', 'working_days', 'status', 'reason', 'replacement_uid', 'decided_by', 'disability']));

	$entitlements = array_map(static fn (array $r): array => [
		'id' => (int)$r['id'], 'employee' => $r['employee_uid'], 'year' => (int)$r['year'], 'type' => $types[(int)$r['type_id']] ?? null,
		'base' => number($r['base_days']), 'carryOver' => number($r['carry_over_days']), 'adjustment' => number($r['manual_adjustment']), 'note' => $r['adjustment_note'],
	], rows($db, 'absence_entitlements', ['id', 'employee_uid', 'year', 'type_id', 'base_days', 'carry_over_days', 'manual_adjustment', 'adjustment_note']));

	$eventsTable = $db->tableExists('absence_ent_events') ? 'absence_ent_events' : 'absence_entitlement_events';
	$entitlementEvents = array_map(static fn (array $r): array => [
		'entitlement' => (int)$r['entitlement_id'], 'employee' => $r['employee_uid'], 'actor' => $r['actor_uid'], 'field' => $r['field'],
		'old' => number($r['old_value']), 'new' => number($r['new_value']), 'note' => $r['note'], 'at' => substr((string)$r['created_at'], 0, 19),
	], rows($db, $eventsTable, ['entitlement_id', 'employee_uid', 'actor_uid', 'field', 'old_value', 'new_value', 'note', 'created_at']));

	$hrOnly = [];
	foreach (rows($db, 'absence_leave_types', ['id', 'hr_only']) as $type) {
		$hrOnly[(int)$type['id']] = flag($type['hr_only']);
	}
	$confidentialRequests = [];
	foreach (rows($db, 'absence_requests', ['id', 'type_id']) as $request) {
		$confidentialRequests[(int)$request['id']] = $hrOnly[(int)$request['type_id']] ?? false;
	}
	$comments = array_map(static fn (array $r): array => [
		'request' => (int)$r['request_id'], 'author' => $r['author_uid'], 'body' => $r['body'],
		'onConfidentialAbsence' => $confidentialRequests[(int)$r['request_id']] ?? false,
	], rows($db, 'absence_comments', ['request_id', 'author_uid', 'body']));
	$history = array_map(static fn (array $r): array => ['request' => (int)$r['request_id'], 'actor' => $r['actor_uid'], 'event' => $r['event_type']],
		rows($db, 'absence_request_events', ['request_id', 'actor_uid', 'event_type']));

	$folder = attachmentFolder();
	$attachments = array_map(static function (array $r) use ($folder): array {
		try {
			$hash = sha1($folder->getFile((string)$r['id'])->getContent());
		} catch (NotFoundException) {
			$hash = null;
		}
		return ['request' => (int)$r['request_id'], 'name' => $r['name'], 'mime' => $r['mime'], 'size' => (int)$r['size'], 'sha1' => $hash];
	}, rows($db, 'absence_attachments', ['id', 'request_id', 'name', 'mime', 'size']));

	$leaveTypes = array_map(static fn (array $r): array => [
		'key' => $r['key'], 'label' => $r['label'], 'hrOnly' => flag($r['hr_only']), 'enabled' => flag($r['enabled']),
		'countsAgainstBalance' => flag($r['counts_against_balance']), 'requiresNote' => flag($r['requires_note']),
	], rows($db, 'absence_leave_types', ['key', 'label', 'hr_only', 'enabled', 'counts_against_balance', 'requires_note'], 'key'));

	$appConfig = Server::get(IAppConfig::class);
	$snapshot = [
		'requests' => $requests,
		'entitlements' => $entitlements,
		'entitlementEvents' => $entitlementEvents,
		'comments' => $comments,
		'requestHistory' => $history,
		'attachments' => $attachments,
		'leaveTypes' => $leaveTypes,
		'balances' => balances($requests, $entitlements),
		'regions' => ['country' => personal('holiday_country'), 'region' => personal('holiday_region')],
		'settings' => [
			'default_entitlement' => number($appConfig->getValueFloat(APP, 'default_entitlement', 28.0)),
			'max_concurrent_absences' => $appConfig->getValueInt(APP, 'max_concurrent_absences', 2),
			'carryover_expiry' => $appConfig->getValueString(APP, 'carryover_expiry', '', lazy: true),
		],
	];
	// Contracts exist from the betas on
	if ($db->tableExists('absence_employments')) {
		$snapshot['employments'] = array_map(static fn (array $r): array => ['employee' => $r['employee_uid'], 'start' => day($r['start_date']), 'end' => day($r['end_date'])],
			rows($db, 'absence_employments', ['employee_uid', 'start_date', 'end_date'], 'employee_uid'));
	}
	return $snapshot;
}

/**
 * The parts of a snapshot that are compared one to one, and what is only
 * needed to work out what the upgrade has to do.
 *
 * @return array{kept: array<string, mixed>, weekdays: array<string, string>, contracts: list<array<string, mixed>>, hrGroup: ?string, hrGroupExists: bool}
 */
function before(IDBConnection $db): array {
	$appConfig = Server::get(IAppConfig::class);
	$periods = $db->tableExists('absence_periods') ? 'absence_periods' : ($db->tableExists('absence_contract_periods') ? 'absence_contract_periods' : null);
	return [
		'kept' => snapshot($db),
		'weekdays' => personal('work_weekdays'),
		'contracts' => $periods === null ? [] : contracts($db, $periods),
		'hrGroup' => $appConfig->hasKey(APP, 'hr_group') ? $appConfig->getValueString(APP, 'hr_group') : null,
		'hrGroupExists' => Server::get(IGroupManager::class)->groupExists('hr'),
	];
}

/** @return list<array{employee: string, from: ?string, weekdays: string, percent: ?int}> */
function contracts(IDBConnection $db, string $table): array {
	return array_map(static fn (array $r): array => [
		'employee' => $r['employee_uid'], 'from' => day($r['valid_from']), 'weekdays' => $r['weekdays'], 'percent' => $r['percent'] === null ? null : (int)$r['percent'],
	], rows($db, $table, ['employee_uid', 'valid_from', 'weekdays', 'percent']));
}

/** @param array<string, mixed> $before */
function verify(IDBConnection $db, array $before): int {
	$after = snapshot($db);
	$failures = 0;
	$check = static function (bool $ok, string $what, mixed $expected = null, mixed $got = null) use (&$failures): void {
		echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
		if (!$ok) {
			echo '  expected: ' . json_encode($expected) . "\n  got:      " . json_encode($got) . "\n";
			$failures++;
		}
	};

	foreach ($before['kept'] as $section => $data) {
		$check($data === ($after[$section] ?? null), "$section: the same before and after", $data, $after[$section] ?? null);
	}

	foreach (['absence_entitlement_events', 'absence_contract_periods', 'absence_employee_history'] as $old) {
		$check(!$db->tableExists($old), "$old: gone, its rows are under the new name");
	}

	// A contract HR entered stays; somebody without one gets their own weekdays as contract
	$contracts = contracts($db, 'absence_periods');
	$expected = $before['contracts'];
	$withContract = array_column($before['contracts'], 'employee');
	foreach ($before['weekdays'] as $uid => $stored) {
		$days = weekdays($stored);
		if ($days !== null && !in_array($uid, $withContract, true)) {
			$expected[] = ['employee' => $uid, 'weekdays' => $days, 'percent' => null];
		}
	}
	$comparable = static function (array $rows): array {
		$rows = array_map(static fn (array $r): string => $r['employee'] . ' ' . $r['weekdays'] . ' ' . json_encode($r['percent']) . (isset($r['from']) ? ' ' . $r['from'] : ''), $rows);
		sort($rows);
		return $rows;
	};
	// The date of a converted setting is the upgrade's choice, so it is left out of the comparison
	$known = array_column($before['contracts'], 'employee');
	$got = array_map(static fn (array $r): array => in_array($r['employee'], $known, true) ? $r : ['employee' => $r['employee'], 'weekdays' => $r['weekdays'], 'percent' => $r['percent']], $contracts);
	$check($comparable($expected) === $comparable($got), 'contracts: the ones HR entered, plus one for each own weekdays setting', $comparable($expected), $comparable($got));
	$check(personal('work_weekdays') === [], 'own weekdays settings: all taken over or dropped', [], personal('work_weekdays'));

	$appConfig = Server::get(IAppConfig::class);
	$hrGroup = $before['hrGroup'] ?? ($before['hrGroupExists'] ? 'hr' : '');
	$check($appConfig->getValueString(APP, 'hr_group') === $hrGroup, "HR group: still \"$hrGroup\"", $hrGroup, $appConfig->getValueString(APP, 'hr_group'));

	$wrong = [];
	foreach (rows($db, 'absence_comments', ['id', 'request_id', 'confidential']) as $i => $row) {
		if (flag($row['confidential']) !== $after['comments'][$i]['onConfidentialAbsence']) {
			$wrong[] = (int)$row['id'];
		}
	}
	$check($wrong === [], 'comments: marked confidential exactly on confidential absences', [], $wrong);

	// Without a new version number Nextcloud runs no migration at all
	$apps = Server::get(IAppManager::class);
	$steps = array_map(static fn (string $file): string => substr(basename($file, '.php'), strlen('Version')), glob($apps->getAppPath(APP) . '/lib/Migration/Version*.php') ?: []);
	$qb = $db->getQueryBuilder();
	$result = $qb->select('version')->from('migrations')->where($qb->expr()->eq('app', $qb->createNamedParameter(APP)))->executeQuery();
	$ran = $result->fetchFirstColumn();
	$result->closeCursor();
	$check(array_values(array_diff($steps, $ran)) === [], 'migrations: every step of the code ran', [], array_values(array_diff($steps, $ran)));

	$installed = $appConfig->getValueString(APP, 'installed_version');
	$code = $apps->getAppVersion(APP, false);
	$check($installed === $code, "installed version: the code's ($code)", $code, $installed);

	echo $failures === 0 ? "upgrade check passed\n" : "$failures check(s) failed\n";
	return $failures === 0 ? 0 : 1;
}
