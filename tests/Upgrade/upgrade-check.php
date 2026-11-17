<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Upgrade check from Absence 1.0.12 (commit 0d84f4d), the version HR used
 * before the betas, to the checked-out code.
 *
 *   php upgrade-check.php <server root> seed               on 1.0.12: invented data
 *   php upgrade-check.php <server root> snapshot <file>    write what is stored
 *   php upgrade-check.php <server root> verify <file>      after `occ upgrade`
 *
 * It uses OCP and plain queries only, never the app's own classes, so the
 * same file runs against both versions. Every person and value is invented.
 */

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

/** Weekdays people set for themselves on 1.0.12, and the contract each must become */
const WEEKDAYS = [
	'upgrade-alice' => ['1,2,3,4,5', '1,2,3,4,5'],
	'upgrade-bob' => ['1,2,3,4', '1,2,3,4'],
	'upgrade-carol' => ['5,1,3', '1,3,5'],
	'upgrade-dave' => ['every day', null],
];

$db = Server::get(IDBConnection::class);

if ($mode === 'seed') {
	seed($db);
} elseif ($mode === 'snapshot' && $file !== null) {
	file_put_contents($file, json_encode(snapshot($db), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
} elseif ($mode === 'verify' && $file !== null) {
	exit(verify($db, json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)));
} else {
	exit(2);
}

function seed(IDBConnection $db): void {
	$users = Server::get(IUserManager::class);
	foreach (USERS as $uid) {
		$users->get($uid) ?? $users->createUser($uid, bin2hex(random_bytes(16)) . 'Aa1!');
	}
	$groups = Server::get(IGroupManager::class);
	// The HR group of 1.0.12's default; the setting itself is never stored
	($groups->get('hr') ?? $groups->createGroup('hr'))->addUser($users->get('upgrade-hr'));
	foreach (['upgrade-alice', 'upgrade-bob', 'upgrade-carol'] as $uid) {
		$users->get($uid)->setManagerUids(['upgrade-dave']);
	}

	$config = Server::get(IUserConfig::class);
	foreach (WEEKDAYS as $uid => [$stored]) {
		$config->setValueString($uid, APP, 'work_weekdays', $stored);
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
		'hr_only' => [false, IQueryBuilder::PARAM_BOOL], 'sort_order' => 20,
	]);
	$update = $db->getQueryBuilder();
	$update->update('absence_leave_types')->set('enabled', $update->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
		->where($update->expr()->eq('key', $update->createNamedParameter('special')))->executeStatement();

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
			'start_date' => [new DateTime($from), IQueryBuilder::PARAM_DATE], 'end_date' => [new DateTime($to), IQueryBuilder::PARAM_DATE],
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
		insert($db, 'absence_entitlement_events', [
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
	echo "seeded\n";
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

function day(mixed $value): string {
	return substr((string)$value, 0, 10);
}

function number(mixed $value): string {
	return rtrim(rtrim(sprintf('%.2F', (float)$value), '0'), '.');
}

function tableExists(IDBConnection $db, string $table): bool {
	return $db->tableExists($table);
}

/**
 * What HR entered, in a form that reads the same before and after the upgrade:
 * dates as `YYYY-MM-DD`, numbers without trailing zeros, types by key.
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

	$eventsTable = tableExists($db, 'absence_ent_events') ? 'absence_ent_events' : 'absence_entitlement_events';
	$entitlementEvents = array_map(static fn (array $r): array => [
		'entitlement' => (int)$r['entitlement_id'], 'employee' => $r['employee_uid'], 'actor' => $r['actor_uid'], 'field' => $r['field'],
		'old' => number($r['old_value']), 'new' => number($r['new_value']), 'note' => $r['note'], 'at' => substr((string)$r['created_at'], 0, 19),
	], rows($db, $eventsTable, ['entitlement_id', 'employee_uid', 'actor_uid', 'field', 'old_value', 'new_value', 'note', 'created_at'], 'entitlement_id'));

	$comments = array_map(static fn (array $r): array => ['request' => (int)$r['request_id'], 'author' => $r['author_uid'], 'body' => $r['body']],
		rows($db, 'absence_comments', ['request_id', 'author_uid', 'body']));
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

	$userConfig = Server::get(IUserConfig::class);
	$personal = [];
	foreach (USERS as $uid) {
		$personal[$uid] = [
			'country' => $userConfig->getValueString($uid, APP, 'holiday_country'),
			'region' => $userConfig->getValueString($uid, APP, 'holiday_region'),
		];
	}

	$appConfig = Server::get(IAppConfig::class);
	return [
		'requests' => $requests,
		'entitlements' => $entitlements,
		'entitlementEvents' => $entitlementEvents,
		'comments' => $comments,
		'requestHistory' => $history,
		'attachments' => $attachments,
		'leaveTypes' => $leaveTypes,
		'personal' => $personal,
		'settings' => [
			'default_entitlement' => number($appConfig->getValueFloat(APP, 'default_entitlement', 28.0)),
			'max_concurrent_absences' => $appConfig->getValueInt(APP, 'max_concurrent_absences', 2),
			'carryover_expiry' => $appConfig->getValueString(APP, 'carryover_expiry', '', lazy: true),
		],
	];
}

/** @param array<string, mixed> $before */
function verify(IDBConnection $db, array $before): int {
	$after = snapshot($db);
	$failures = 0;
	$check = static function (bool $ok, string $what) use (&$failures): void {
		echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
		$failures += $ok ? 0 : 1;
	};

	foreach ($before as $section => $data) {
		$same = $data === $after[$section];
		$check($same, "$section kept as entered");
		if (!$same) {
			echo '  before: ' . json_encode($data) . "\n  after:  " . json_encode($after[$section]) . "\n";
		}
	}

	$check(!tableExists($db, 'absence_entitlement_events'), 'old entitlement history table is gone after its rows were copied');

	$userConfig = Server::get(IUserConfig::class);
	foreach (WEEKDAYS as $uid => [, $expected]) {
		$periods = array_values(array_filter(
			rows($db, 'absence_periods', ['employee_uid', 'weekdays', 'percent']),
			static fn (array $r): bool => $r['employee_uid'] === $uid,
		));
		$check(
			$expected === null ? $periods === [] : (count($periods) === 1 && $periods[0]['weekdays'] === $expected),
			$expected === null ? "$uid: an invalid weekdays setting makes no contract" : "$uid: own weekdays became a contract ($expected)",
		);
		$check($userConfig->getValueString($uid, APP, 'work_weekdays') === '', "$uid: the old weekdays setting is cleaned up");
	}

	$appConfig = Server::get(IAppConfig::class);
	$check($appConfig->getValueString(APP, 'hr_group') === 'hr', 'the HR group 1.0.12 used by default is kept');

	$confidential = [];
	foreach (rows($db, 'absence_comments', ['request_id', 'confidential']) as $row) {
		$confidential[(int)$row['request_id']][] = flag($row['confidential']);
	}
	$maternity = array_values(array_filter($after['requests'], static fn (array $r): bool => $r['type'] === 'maternity'))[0]['id'];
	$check(($confidential[$maternity] ?? []) === [true], 'comments of a confidential absence are marked confidential');
	$check(array_sum(array_map(static fn (array $flags): int => count(array_filter($flags)), $confidential)) === 1, 'no other comment is marked confidential');

	$installed = $appConfig->getValueString(APP, 'installed_version');
	// Nextcloud blocks loading XML from files, so the text is read first
	$code = (string)simplexml_load_string((string)file_get_contents(__DIR__ . '/../../appinfo/info.xml'))->version;
	$check($installed === $code, "installed version is the code's ($installed)");

	echo $failures === 0 ? "upgrade check passed\n" : "$failures check(s) failed\n";
	return $failures === 0 ? 0 : 1;
}
