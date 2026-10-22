<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\EntitlementMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\ValidationException;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Everybody's region code, start and end date, contract and vacation days as
 * one JSON list HR edits and imports again (§6.5). An entry replaces what it
 * names for that employee; employees not in the list are left alone, and so
 * are the years an entry's vacation days leave out. The import checks every
 * entry first and writes nothing while one is wrong, and every change goes
 * through {@see EmployeeService}, which keeps the history and the audit log.
 *
 * @psalm-import-type Contract from EmployeeService
 * @psalm-import-type Period from EmployeeService
 * @psalm-type Entry = array{user: string, name: string, region: ?string, start: ?string, end: ?string, contract: list<Period>, vacation: array<string, float>}
 * @psalm-type Change = array{user: string, name: string, line: ?int, fields: list<array{field: string, year?: int, before: mixed, after: mixed}>}
 * @psalm-type Problem = array{line: ?int, entry: ?int, message: string}
 * @psalm-type Plan = array{changes: list<Change>, unchanged: int, errors: list<Problem>}
 */
class EmployeeJsonService {
	public const FIELDS = ['region', 'start', 'end', 'contract'];
	private const IMPORT_LIMIT = 5000;
	private const ANNUAL = 'annual';
	// Years HR may set vacation days for, around the current one
	private const PAST_YEARS = 2;
	private const FUTURE_YEARS = 1;

	public function __construct(
		private EmployeeDirectory $directory,
		private EmployeeService $employees,
		private ConfigService $config,
		private HolidayService $holidays,
		private EntitlementService $entitlements,
		private EntitlementMapper $entitlementMapper,
		private LeaveTypeMapper $leaveTypes,
		private BalanceService $balances,
		private IUserManager $userManager,
		private IDBConnection $db,
		private IL10N $l,
	) {
	}

	/**
	 * Every employee in the import format, by name.
	 *
	 * @return list<Entry>
	 */
	public function export(): array {
		$uids = $this->directory->listAll();
		$this->employees->preload($uids);
		$regions = $this->config->getRegionCodes();
		$year = $this->balances->currentYear();
		$vacation = $this->vacationDays([$year, $year + 1]);
		$default = $this->config->getDefaultEntitlement();
		$entries = [];
		foreach ($uids as $uid) {
			$contract = $this->employees->getContract($uid);
			// The current year always, with the default while HR set nothing; the next one once set
			$days = [(string)$year => $vacation[$uid][$year] ?? $default];
			if (isset($vacation[$uid][$year + 1])) {
				$days[(string)($year + 1)] = $vacation[$uid][$year + 1];
			}
			$entries[] = [
				'user' => $uid,
				'name' => $this->displayName($uid),
				'region' => ($regions[$uid] ?? '') === '' ? null : $regions[$uid],
				'start' => $contract['start'],
				'end' => $contract['end'],
				'contract' => $contract['periods'],
				'vacation' => $days,
			];
		}
		usort($entries, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']) ?: strcmp($a['user'], $b['user']));
		return $entries;
	}

	/**
	 * The list as text: one key per line, one contract period per line.
	 *
	 * @param list<Entry> $entries
	 */
	public static function format(array $entries): string {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
		$blocks = [];
		foreach ($entries as $entry) {
			$lines = [];
			foreach ($entry as $key => $value) {
				if ($key === 'contract') {
					$periods = array_map(static fn (array $period): string => "\t\t\t" . sprintf(
						'{ "from": %s, "weekdays": [%s], "percent": %s }',
						json_encode($period['from'], $flags), implode(', ', $period['weekdays']), json_encode($period['percent'], $flags),
					), $value);
					$lines[] = "\t\t\"contract\": " . ($periods === [] ? '[]' : "[\n" . implode(",\n", $periods) . "\n\t\t]");
				} elseif ($key === 'vacation') {
					$years = array_map(static fn (string $year, float $days): string => json_encode($year, $flags) . ': ' . json_encode($days, $flags), array_map('strval', array_keys($value)), $value);
					$lines[] = "\t\t\"vacation\": " . ($years === [] ? '{}' : '{ ' . implode(', ', $years) . ' }');
				} else {
					$lines[] = "\t\t" . json_encode($key, $flags) . ': ' . json_encode($value, $flags);
				}
			}
			$blocks[] = "\t{\n" . implode(",\n", $lines) . "\n\t}";
		}
		return $blocks === [] ? "[]\n" : "[\n" . implode(",\n", $blocks) . "\n]\n";
	}

	/**
	 * What importing the list would change, without writing anything.
	 *
	 * @return Plan
	 */
	public function plan(string $json): array {
		return $this->check($json)['plan'];
	}

	/**
	 * Writes the list, all of it or nothing.
	 *
	 * @return Plan
	 * @throws ValidationException while an entry is wrong
	 */
	public function import(string $json, ?string $actor): array {
		['plan' => $plan, 'writes' => $writes] = $this->check($json);
		if ($plan['errors'] !== []) {
			throw new ValidationException($this->l->n('Nothing was saved: %n entry is wrong.', 'Nothing was saved: %n entries are wrong.', count($plan['errors'])));
		}
		$this->db->beginTransaction();
		try {
			foreach ($writes as $uid => $write) {
				// PHP turns numeric uids used as keys into integers
				$uid = (string)$uid;
				if ($write['contract'] !== null) {
					$this->employees->saveContract($uid, $write['contract']['start'], $write['contract']['end'], $write['contract']['periods'], $actor);
				}
				if ($write['region'] !== null) {
					$this->employees->saveRegion($uid, $write['region'], $actor);
				}
				foreach ($write['vacation'] as $year => $days) {
					$this->entitlements->setForEmployee($actor ?? 'import:cli', $uid, $year, $this->annualTypeId(), ['baseDays' => $days]);
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $plan;
	}

	/**
	 * The line each entry of a JSON list starts on, for messages that point
	 * into the text HR edited. Expects valid JSON.
	 *
	 * @return list<int>
	 */
	public static function entryLines(string $json): array {
		$lines = [];
		$line = 1;
		$depth = 0;
		$inString = false;
		$escaped = false;
		$expectEntry = false;
		$length = strlen($json);
		for ($i = 0; $i < $length; $i++) {
			$char = $json[$i];
			if ($char === "\n") {
				$line++;
				continue;
			}
			if ($inString) {
				if ($escaped) {
					$escaped = false;
				} elseif ($char === '\\') {
					$escaped = true;
				} elseif ($char === '"') {
					$inString = false;
				}
				continue;
			}
			if ($char === ' ' || $char === "\t" || $char === "\r") {
				continue;
			}
			if ($depth === 1 && $expectEntry && $char !== ']') {
				$lines[] = $line;
				$expectEntry = false;
			}
			if ($char === '"') {
				$inString = true;
			} elseif ($char === '{' || $char === '[') {
				$depth++;
				$expectEntry = $depth === 1;
			} elseif ($char === '}' || $char === ']') {
				$depth--;
			} elseif ($char === ',' && $depth === 1) {
				$expectEntry = true;
			}
		}
		return $lines;
	}

	/**
	 * @return array{plan: Plan, writes: array<string, array{contract: ?Contract, region: ?string, vacation: array<int, float>}>}
	 */
	private function check(string $json): array {
		try {
			$entries = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			return $this->failed([$this->problem(null, null, $this->l->t('This is not valid JSON: %s', [$e->getMessage()]))]);
		}
		if (!is_array($entries) || !array_is_list($entries)) {
			return $this->failed([$this->problem(null, null, $this->l->t('Expected a list of employees, like [{"user": "jdoe", "region": "DE-BY", "start": null, "end": null, "contract": []}]'))]);
		}
		if (count($entries) > self::IMPORT_LIMIT) {
			return $this->failed([$this->problem(null, null, $this->l->t('At most %d employees per import', [self::IMPORT_LIMIT]))]);
		}

		$lines = self::entryLines($json);
		$errors = [];
		$seen = [];
		$wanted = [];
		foreach ($entries as $index => $entry) {
			$line = $lines[$index] ?? null;
			try {
				[$uid, $after] = $this->read($entry, $seen);
				$seen[$uid] = $index + 1;
				$wanted[$uid] = ['line' => $line, 'after' => $after];
			} catch (ValidationException $e) {
				$errors[] = $this->problem($line, $index + 1, $e->getMessage());
			}
		}
		if ($errors !== []) {
			return $this->failed($errors);
		}
		return $this->compare($wanted);
	}

	/**
	 * Checks one entry and puts it in the stored form.
	 *
	 * @param array<string, int> $seen uid => entry number, of the entries before
	 * @return array{0: string, 1: array{region: string, contract: Contract, vacation: array<int, float>}}
	 * @throws ValidationException
	 */
	private function read(mixed $entry, array $seen): array {
		if (!is_array($entry) || array_is_list($entry)) {
			throw new ValidationException($this->l->t('Each entry is an object with "user", "region", "start", "end" and "contract"'));
		}
		$user = $entry['user'] ?? null;
		if (!is_string($user) || trim($user) === '') {
			throw new ValidationException($this->l->t('An entry has no "user"'));
		}
		$user = trim($user);
		$uid = $this->directory->resolveUser($user);
		if ($uid === null) {
			throw new ValidationException($this->l->t('%s is not a user', [$user]));
		}
		if (!$this->directory->isEmployee($uid)) {
			throw new ValidationException($this->l->t('%s is not an employee', [$user]));
		}
		if (isset($seen[$uid])) {
			throw new ValidationException($this->l->t('%1$s is already in entry %2$d', [$user, $seen[$uid]]));
		}
		$name = $this->displayName($uid);
		try {
			return [$uid, $this->readFields($entry)];
		} catch (ValidationException $e) {
			throw new ValidationException($this->l->t('%1$s: %2$s', [$name, $e->getMessage()]));
		}
	}

	/**
	 * @param array<array-key, mixed> $entry
	 * @return array{region: string, contract: Contract, vacation: array<int, float>}
	 * @throws ValidationException
	 */
	private function readFields(array $entry): array {
		foreach (self::FIELDS as $field) {
			if (!array_key_exists($field, $entry)) {
				throw new ValidationException($this->l->t('"%s" is missing, write null to leave it empty', [$field]));
			}
		}

		$region = $entry['region'];
		if ($region !== null && !is_string($region)) {
			throw new ValidationException($this->l->t('the region code is text, like "DE-BY", or null'));
		}
		$region = $region === null || trim($region) === '' ? '' : $this->holidays->normalizeRegion($region);
		if ($region === HolidayService::EVERYWHERE) {
			throw new ValidationException($this->l->t('* is for holidays everywhere, not a person\'s region code'));
		}

		foreach (['start', 'end'] as $field) {
			if ($entry[$field] !== null && !is_string($entry[$field])) {
				throw new ValidationException($this->l->t('"%s" is a date written YYYY-MM-DD, or null', [$field]));
			}
		}
		if (!is_array($entry['contract']) || !array_is_list($entry['contract'])) {
			throw new ValidationException($this->l->t('"contract" is a list of periods, like [{"from": "2026-01-01", "weekdays": [1, 2, 3, 4, 5], "percent": 100}]'));
		}
		foreach ($entry['contract'] as $period) {
			if (!is_array($period)) {
				throw new ValidationException($this->l->t('each contract period is an object with "from", "weekdays" and "percent"'));
			}
		}
		/** @var list<array{from?: mixed, weekdays?: mixed, percent?: mixed}> $periods */
		$periods = $entry['contract'];
		return [
			'region' => $region,
			'contract' => $this->employees->validate($entry['start'], $entry['end'], $periods),
			'vacation' => $this->readVacation($entry['vacation'] ?? []),
		];
	}

	/**
	 * The vacation days by year, optional: years left out keep their days.
	 *
	 * @return array<int, float>
	 * @throws ValidationException
	 */
	private function readVacation(mixed $vacation): array {
		// json_decode() turns {} into [] and year keys into integers
		if (!is_array($vacation) || ($vacation !== [] && array_is_list($vacation))) {
			throw new ValidationException($this->l->t('"vacation" is the days per year, like {"2026": 30}'));
		}
		$current = $this->balances->currentYear();
		$days = [];
		foreach ($vacation as $year => $value) {
			if (!preg_match('/^\d{4}$/', (string)$year) || (int)$year < $current - self::PAST_YEARS || (int)$year > $current + self::FUTURE_YEARS) {
				throw new ValidationException($this->l->t('vacation days can be set for %1$d to %2$d, not %3$s', [$current - self::PAST_YEARS, $current + self::FUTURE_YEARS, (string)$year]));
			}
			if ((!is_int($value) && !is_float($value)) || $value < 0 || $value > 366 || round((float)$value, 1) !== (float)$value) {
				throw new ValidationException($this->l->t('the vacation days of %d are a number of days, like 30 or 27.5', [(int)$year]));
			}
			$days[(int)$year] = (float)$value;
		}
		ksort($days);
		return $days;
	}

	/**
	 * @param array<string, array{line: ?int, after: array{region: string, contract: Contract, vacation: array<int, float>}}> $wanted
	 * @return array{plan: Plan, writes: array<string, array{contract: ?Contract, region: ?string, vacation: array<int, float>}>}
	 */
	private function compare(array $wanted): array {
		$uids = array_map('strval', array_keys($wanted));
		$this->employees->preload($uids);
		$regions = $this->config->getRegionCodes();
		$years = array_values(array_unique(array_merge([], ...array_map(static fn (array $want): array => array_keys($want['after']['vacation']), array_values($wanted)))));
		$vacation = $years === [] ? [] : $this->vacationDays($years);
		$default = $this->config->getDefaultEntitlement();

		$changes = [];
		$writes = [];
		$unchanged = 0;
		foreach ($wanted as $uid => ['line' => $line, 'after' => $after]) {
			$uid = (string)$uid;
			$before = $this->employees->getContract($uid);
			$region = $regions[$uid] ?? '';
			$fields = [];
			if ($region !== $after['region']) {
				$fields[] = ['field' => 'region', 'before' => $region === '' ? null : $region, 'after' => $after['region'] === '' ? null : $after['region']];
			}
			foreach (['start', 'end'] as $field) {
				if ($before[$field] !== $after['contract'][$field]) {
					$fields[] = ['field' => $field, 'before' => $before[$field], 'after' => $after['contract'][$field]];
				}
			}
			if ($before['periods'] !== $after['contract']['periods']) {
				$fields[] = ['field' => 'contract', 'before' => $before['periods'], 'after' => $after['contract']['periods']];
			}
			$days = [];
			foreach ($after['vacation'] as $year => $wantedDays) {
				// No row yet means the default, which is what the balance shows
				$current = $vacation[$uid][$year] ?? $default;
				if ($current !== $wantedDays) {
					$days[$year] = $wantedDays;
					$fields[] = ['field' => 'vacation', 'year' => $year, 'before' => $current, 'after' => $wantedDays];
				}
			}
			if ($fields === []) {
				$unchanged++;
				continue;
			}
			$contractChanges = $before !== $after['contract'];
			$changes[] = ['user' => $uid, 'name' => $this->displayName($uid), 'line' => $line, 'fields' => $fields];
			$writes[$uid] = [
				'contract' => $contractChanges ? $after['contract'] : null,
				'region' => $region !== $after['region'] ? $after['region'] : null,
				'vacation' => $days,
			];
		}

		return ['plan' => ['changes' => $changes, 'unchanged' => $unchanged, 'errors' => []], 'writes' => $writes];
	}

	/**
	 * @param list<Problem> $errors
	 * @return array{plan: Plan, writes: array<string, array{contract: ?Contract, region: ?string, vacation: array<int, float>}>}
	 */
	private function failed(array $errors): array {
		return ['plan' => ['changes' => [], 'unchanged' => 0, 'errors' => $errors], 'writes' => []];
	}

	/**
	 * @return Problem
	 */
	private function problem(?int $line, ?int $entry, string $message): array {
		return ['line' => $line, 'entry' => $entry, 'message' => $message];
	}

	/**
	 * The annual base days HR set, by employee and year.
	 *
	 * @param list<int> $years
	 * @return array<string, array<int, float>>
	 */
	private function vacationDays(array $years): array {
		$typeId = $this->annualTypeId();
		$days = [];
		foreach ($years as $year) {
			foreach ($this->entitlementMapper->findForYear($year) as $entitlement) {
				if ($entitlement->getTypeId() === $typeId) {
					$days[$entitlement->getEmployeeUid()][$year] = $entitlement->getBaseDays();
				}
			}
		}
		return $days;
	}

	private function annualTypeId(): int {
		foreach ($this->leaveTypes->findAll() as $type) {
			if ($type->getKey() === self::ANNUAL) {
				return $type->getId();
			}
		}
		throw new ValidationException($this->l->t('There is no annual leave type to set vacation days for'));
	}

	private function displayName(string $uid): string {
		return $this->userManager->get($uid)?->getDisplayName() ?? $uid;
	}
}
