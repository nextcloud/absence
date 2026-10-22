<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\ConfigLexicon;
use OCA\Absence\Db\ContractPeriod;
use OCA\Absence\Db\ContractPeriodMapper;
use OCA\Absence\Db\EmployeeChange;
use OCA\Absence\Db\EmployeeChangeMapper;
use OCA\Absence\Db\Employment;
use OCA\Absence\Db\EmploymentMapper;
use OCA\Absence\Exception\NotFoundException;
use OCA\Absence\Exception\ValidationException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Log\Audit\CriticalActionPerformedEvent;

/**
 * The contract data HR keeps per employee (§6.4): start and end date, and the
 * working weekdays and contract percentage from a date on. Every change is kept
 * in the employee's history and sent to the admin audit log.
 *
 * @psalm-type Period = array{from: string, weekdays: list<int>, percent: ?int}
 * @psalm-type Contract = array{start: ?string, end: ?string, periods: list<Period>}
 */
class EmployeeService {
	private const DATE = '/^\d{4}-\d{2}-\d{2}$/';
	/** @var array<string, Contract> */
	private array $contracts = [];

	public function __construct(
		private EmploymentMapper $employments,
		private ContractPeriodMapper $periods,
		private EmployeeChangeMapper $history,
		private EmployeeDirectory $directory,
		private ConfigService $config,
		private ClockService $clock,
		private IDBConnection $db,
		private IEventDispatcher $dispatcher,
		private IL10N $l,
	) {
	}

	/**
	 * @return Contract
	 */
	public function getContract(string $uid): array {
		$this->preload([$uid]);
		return $this->contracts[$uid];
	}

	/**
	 * Loads the contracts of many employees in a fixed number of queries, for the
	 * balances of a whole company.
	 *
	 * @param list<string> $uids
	 */
	public function preload(array $uids): void {
		$missing = array_values(array_diff($uids, array_keys($this->contracts)));
		foreach (array_chunk($missing, 1000) as $chunk) {
			foreach ($chunk as $uid) {
				$this->contracts[$uid] = ['start' => null, 'end' => null, 'periods' => []];
			}
			foreach ($this->employments->findForEmployees($chunk) as $employment) {
				$this->contracts[$employment->getEmployeeUid()]['start'] = $employment->getStartDate();
				$this->contracts[$employment->getEmployeeUid()]['end'] = $employment->getEndDate();
			}
			foreach ($this->periods->findForEmployees($chunk) as $period) {
				$this->contracts[$period->getEmployeeUid()]['periods'][] = $this->describePeriod($period);
			}
		}
	}

	/**
	 * Removes someone's contract data and its history, when their account goes.
	 */
	public function purge(string $uid): void {
		unset($this->contracts[$uid]);
		foreach (['absence_employments', 'absence_contract_periods', 'absence_employee_history'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('employee_uid', $qb->createNamedParameter($uid)))->executeStatement();
		}
	}

	/**
	 * The weekdays someone works on a day, or null when HR has not entered any.
	 *
	 * @return list<int>|null ISO weekdays, Mon=1
	 */
	public function weekdaysOn(string $uid, string $date): ?array {
		$current = null;
		foreach ($this->getContract($uid)['periods'] as $period) {
			if ($period['from'] > $date) {
				break;
			}
			$current = $period;
		}
		return $current['weekdays'] ?? null;
	}

	/**
	 * Replaces someone's contract data with what HR entered.
	 *
	 * @param list<array{from?: mixed, weekdays?: mixed, percent?: mixed}> $periods
	 * @return Contract
	 * @throws NotFoundException|ValidationException
	 */
	public function saveContract(string $uid, ?string $start, ?string $end, array $periods, ?string $actor): array {
		if (!$this->directory->isEmployee($uid)) {
			throw new NotFoundException($this->l->t('Employee not found'));
		}
		$after = $this->validate($start, $end, $periods);
		$before = $this->getContract($uid);
		if ($before === $after) {
			return $before;
		}

		$this->db->beginTransaction();
		try {
			$employment = $this->employments->findForEmployee($uid);
			if ($employment === null) {
				$employment = new Employment();
				$employment->setEmployeeUid($uid);
			}
			$employment->setStartDate($after['start']);
			$employment->setEndDate($after['end']);
			$employment->getId() === null ? $this->employments->insert($employment) : $this->employments->update($employment);

			$this->periods->deleteForEmployee($uid);
			foreach ($after['periods'] as $entry) {
				$period = new ContractPeriod();
				$period->setEmployeeUid($uid);
				$period->setValidFrom($entry['from']);
				$period->setWeekdays(implode(',', $entry['weekdays']));
				$period->setPercent($entry['percent']);
				$this->periods->insert($period);
			}
			$this->record($uid, EmployeeChange::CONTRACT, $before, $after, $actor);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->contracts[$uid] = $after;
		$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(
			'Contract of %s changed by %s', [$uid, $actor ?? 'occ'],
		));
		return $after;
	}

	/**
	 * Sets someone's region code (§7.2) on their behalf, which their history and
	 * the admin audit log keep with who did it.
	 *
	 * @param string $code a code as `DE-BY`, or '' for none
	 * @throws NotFoundException|ValidationException
	 */
	public function saveRegion(string $uid, string $code, ?string $actor): void {
		if (!$this->directory->isEmployee($uid)) {
			throw new NotFoundException($this->l->t('Employee not found'));
		}
		$personal = $this->config->getPersonalConfig($uid);
		$before = ConfigService::regionCode($personal[ConfigLexicon::KEY_HOLIDAY_COUNTRY], $personal[ConfigLexicon::KEY_HOLIDAY_REGION]);
		if ($before === $code) {
			return;
		}
		[$country, $region] = array_pad(explode('-', $code, 2), 2, '');
		$this->config->setPersonalValue($uid, ConfigLexicon::KEY_HOLIDAY_COUNTRY, $country);
		$this->config->setPersonalValue($uid, ConfigLexicon::KEY_HOLIDAY_REGION, $region);
		$this->recordRegionChange($uid, $before, $code, $actor);
		$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(
			'Region code of %s changed from "%s" to "%s" by %s', [$uid, $before, $code, $actor ?? 'occ'],
		));
	}

	/**
	 * Keeps a region code change in the person's history.
	 */
	public function recordRegionChange(string $uid, string $before, string $after, ?string $actor): void {
		if ($before !== $after) {
			$this->record($uid, EmployeeChange::REGION, $before, $after, $actor);
		}
	}

	/**
	 * @return list<array{field: string, actor: ?string, before: mixed, after: mixed, createdAt: string}>
	 */
	public function history(string $uid): array {
		return array_map(static function (EmployeeChange $change): array {
			/** @var array{before: mixed, after: mixed} $detail */
			$detail = json_decode($change->getDetail(), true, 512, JSON_THROW_ON_ERROR);
			return [
				'field' => $change->getField(),
				'actor' => $change->getActorUid(),
				'before' => $detail['before'],
				'after' => $detail['after'],
				'createdAt' => $change->getCreatedAt()->format(\DateTimeInterface::ATOM),
			];
		}, $this->history->findForEmployee($uid));
	}

	/**
	 * Checks contract data and puts it in the stored form: periods sorted by
	 * date, weekdays sorted and unique.
	 *
	 * @param list<array{from?: mixed, weekdays?: mixed, percent?: mixed}> $periods
	 * @return Contract
	 * @throws ValidationException
	 */
	public function validate(?string $start, ?string $end, array $periods): array {
		$start = $start === '' ? null : $start;
		$end = $end === '' ? null : $end;
		foreach ([$start, $end] as $date) {
			if ($date !== null && !$this->isDate($date)) {
				throw new ValidationException($this->l->t('Dates are written YYYY-MM-DD'));
			}
		}
		if ($start !== null && $end !== null && $end < $start) {
			throw new ValidationException($this->l->t('The end date is before the start date'));
		}

		$clean = [];
		foreach ($periods as $period) {
			$from = $period['from'] ?? null;
			if (!is_string($from) || !$this->isDate($from)) {
				throw new ValidationException($this->l->t('Each change needs a start date written YYYY-MM-DD'));
			}
			if (isset($clean[$from])) {
				throw new ValidationException($this->l->t('Two changes start on %s', [$from]));
			}
			$weekdays = $period['weekdays'] ?? null;
			if (!is_array($weekdays) || $weekdays === []) {
				throw new ValidationException($this->l->t('Each change needs at least one working day'));
			}
			$days = [];
			foreach ($weekdays as $day) {
				if (!is_int($day) || $day < 1 || $day > 7) {
					throw new ValidationException($this->l->t('Working days are 1 (Monday) to 7 (Sunday)'));
				}
				$days[$day] = $day;
			}
			sort($days);
			$percent = $period['percent'] ?? null;
			if ($percent !== null && (!is_int($percent) || $percent < 1 || $percent > 100)) {
				throw new ValidationException($this->l->t('The contract percentage is between 1 and 100'));
			}
			$clean[$from] = ['from' => $from, 'weekdays' => $days, 'percent' => $percent];
		}
		ksort($clean);
		return ['start' => $start, 'end' => $end, 'periods' => array_values($clean)];
	}

	private function isDate(string $value): bool {
		if (!preg_match(self::DATE, $value)) {
			return false;
		}
		[$year, $month, $day] = array_map('intval', explode('-', $value));
		return checkdate($month, $day, $year);
	}

	/**
	 * @return Period
	 */
	private function describePeriod(ContractPeriod $period): array {
		return ['from' => $period->getValidFrom(), 'weekdays' => $period->getWeekdayList(), 'percent' => $period->getPercent()];
	}

	private function record(string $uid, string $field, mixed $before, mixed $after, ?string $actor): void {
		$change = new EmployeeChange();
		$change->setEmployeeUid($uid);
		$change->setActorUid($actor);
		$change->setField($field);
		$change->setDetail(json_encode(['before' => $before, 'after' => $after], JSON_THROW_ON_ERROR));
		$change->setCreatedAt($this->clock->now());
		$this->history->insert($change);
	}
}
