<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use Closure;
use OCA\Absence\AppInfo\Application;
use OCA\Absence\Db\EmployeeChange;
use OCA\Absence\Service\ClockService;
use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The weekdays people set for themselves before HR kept contracts (§6.4)
 * become a contract period for everyone who has none yet, with an entry in
 * their history that names their own setting as the source. The period starts
 * on January 1 of the current year: the setting has no date, it was in use
 * before the upgrade, and the start of the year covers the leave of the year
 * that is still being counted. HR moves the date when it is wrong. The setting
 * is then deleted for everybody, as HR owns the weekdays now.
 */
class Version1018Date20261117180000 extends SimpleMigrationStep {
	private const KEY = 'work_weekdays';
	private const CHUNK = 500;
	/** The setting only exists on instances older than contract data: the history starts from none */
	private const NO_CONTRACT = ['start' => null, 'end' => null, 'periods' => []];

	public function __construct(
		private IDBConnection $db,
		private IUserConfig $userConfig,
		private ClockService $clock,
	) {
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$values = $this->ownWeekdays();
		if ($values === []) {
			return;
		}

		$from = $this->clock->serverYear() . '-01-01';
		$converted = 0;
		foreach (array_chunk($values, self::CHUNK, true) as $chunk) {
			$weekdays = [];
			foreach ($chunk as $uid => $value) {
				$days = self::parse($value);
				if ($days !== null) {
					$weekdays[(string)$uid] = $days;
				}
			}
			$weekdays = array_diff_key($weekdays, array_flip($this->withPeriods(array_map(strval(...), array_keys($weekdays)))));
			$converted += $this->convert($weekdays, $from);
		}
		$this->userConfig->deleteKey(Application::APP_ID, self::KEY);
		$output->info(sprintf('Turned the own working weekdays of %d people into contract periods', $converted));
	}

	/**
	 * Read from the table: the typed read of the server fails on an empty value,
	 * which Oracle stores as NULL.
	 *
	 * @return array<array-key, string> uid => stored value
	 */
	private function ownWeekdays(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('userid', 'configvalue')
			->from('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(self::KEY)));
		$result = $qb->executeQuery();
		$values = [];
		while ($row = $result->fetch()) {
			$values[$row['userid']] = (string)($row['configvalue'] ?? '');
		}
		$result->closeCursor();
		return $values;
	}

	/**
	 * Comma separated ISO weekdays, at least one and each once, sorted.
	 *
	 * @return list<int>|null
	 */
	private static function parse(string $value): ?array {
		if (!preg_match('/^[1-7](,[1-7])*$/', $value)) {
			return null;
		}
		$days = array_map(intval(...), explode(',', $value));
		if (count(array_unique($days)) !== count($days)) {
			return null;
		}
		sort($days);
		return $days;
	}

	/**
	 * @param list<string> $uids
	 * @return list<string> the uids that already have a contract period
	 */
	private function withPeriods(array $uids): array {
		if ($uids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('employee_uid')
			->from('absence_periods')
			->where($qb->expr()->in('employee_uid', $qb->createNamedParameter($uids, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$found = array_map(strval(...), $result->fetchFirstColumn());
		$result->closeCursor();
		return $found;
	}

	/**
	 * One period and one history entry per person, written together.
	 *
	 * @param array<string, list<int>> $weekdays
	 */
	private function convert(array $weekdays, string $from): int {
		if ($weekdays === []) {
			return 0;
		}
		$now = $this->clock->now();
		$this->db->beginTransaction();
		try {
			foreach ($weekdays as $uid => $days) {
				$uid = (string)$uid;
				$period = $this->db->getQueryBuilder();
				$period->insert('absence_periods')->values([
					'employee_uid' => $period->createNamedParameter($uid),
					'valid_from' => $period->createNamedParameter($from),
					'weekdays' => $period->createNamedParameter(implode(',', $days)),
					'percent' => $period->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				])->executeStatement();

				$after = self::NO_CONTRACT;
				$after['periods'] = [['from' => $from, 'weekdays' => $days, 'percent' => null]];
				$history = $this->db->getQueryBuilder();
				$history->insert('absence_emp_history')->values([
					'employee_uid' => $history->createNamedParameter($uid),
					'actor_uid' => $history->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
					'field' => $history->createNamedParameter(EmployeeChange::CONTRACT),
					'detail' => $history->createNamedParameter(json_encode(['before' => self::NO_CONTRACT, 'after' => $after, 'source' => 'own_setting'], JSON_THROW_ON_ERROR)),
					'created_at' => $history->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE),
				])->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return count($weekdays);
	}
}
