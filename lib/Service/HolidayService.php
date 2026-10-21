<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\Holiday;
use OCA\Absence\Db\HolidayMapper;
use OCA\Absence\Exception\NotFoundException;
use OCA\Absence\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * The public holidays HR maintains by hand (§7.2). A region code is a country
 * (`DE`), a subdivision (`DE-BY`) or `*` for days off everywhere; someone in
 * `DE-BY` gets the `DE-BY`, `DE` and `*` days.
 */
class HolidayService {
	public const EVERYWHERE = '*';
	// Must stay within the column lengths in Version1000Date20260710000000.
	private const MAX_LABEL_LENGTH = 128;
	private const IMPORT_LIMIT = 5000;

	public function __construct(
		private HolidayMapper $mapper,
		private IDBConnection $db,
		private IL10N $l,
	) {
	}

	/**
	 * @return list<Holiday> in date order, recurring ones on their date in that year
	 */
	public function listForYear(int $year, ?string $region = null): array {
		$regions = $region === null ? null : [$this->normalizeRegion($region)];
		return $this->expand($this->mapper->findInRange("$year-01-01", "$year-12-31", $regions), "$year-01-01", "$year-12-31");
	}

	/**
	 * The days off that apply to people with these region codes.
	 *
	 * @param list<string> $codes region codes, invalid ones are ignored
	 * @return list<Holiday> in date order
	 */
	public function forRegions(array $codes, string $from, string $to): array {
		$regions = [self::EVERYWHERE];
		foreach ($codes as $code) {
			try {
				$code = $this->normalizeRegion($code);
			} catch (ValidationException) {
				continue;
			}
			$regions[] = $code;
			$regions[] = explode('-', $code)[0];
		}
		return $this->expand($this->mapper->findInRange($from, $to, array_values(array_unique($regions))), $from, $to);
	}

	/**
	 * @throws ValidationException
	 */
	public function create(string $date, string $region, string $label, bool $recurring = false): Holiday {
		$holiday = new Holiday();
		$this->fill($holiday, $date, $region, $label, $recurring);
		return $this->mapper->insert($holiday);
	}

	/**
	 * @throws ValidationException|NotFoundException
	 */
	public function update(int $id, string $date, string $region, string $label, bool $recurring = false): Holiday {
		$holiday = $this->find($id);
		$this->fill($holiday, $date, $region, $label, $recurring);
		return $this->mapper->update($holiday);
	}

	/**
	 * @throws NotFoundException
	 */
	public function delete(int $id): Holiday {
		return $this->mapper->delete($this->find($id));
	}

	/**
	 * Checks a JSON list of `{date, region, label, recurring?}` without writing.
	 * Days already in the list (same region and date) are skipped.
	 *
	 * @return array{new: list<array{date: string, region: string, label: string, recurring: bool}>, existing: int}
	 * @throws ValidationException naming every invalid entry, nothing is imported then
	 */
	public function plan(string $json): array {
		try {
			$entries = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new ValidationException($this->l->t('This is not valid JSON: %s', [$e->getMessage()]));
		}
		if (!is_array($entries) || !array_is_list($entries)) {
			throw new ValidationException($this->l->t('Expected a list of holidays, like [{"date": "2027-01-01", "region": "DE", "label": "New Year"}]'));
		}
		if (count($entries) > self::IMPORT_LIMIT) {
			throw new ValidationException($this->l->t('At most %d holidays per import', [self::IMPORT_LIMIT]));
		}

		$new = [];
		$errors = [];
		$existing = 0;
		$seen = [];
		foreach ($entries as $i => $entry) {
			try {
				if (!is_array($entry)) {
					throw new ValidationException($this->l->t('not an object'));
				}
				$holiday = new Holiday();
				$this->fill(
					$holiday,
					is_string($entry['date'] ?? null) ? $entry['date'] : '',
					is_string($entry['region'] ?? null) ? $entry['region'] : '',
					is_string($entry['label'] ?? null) ? $entry['label'] : '',
					($entry['recurring'] ?? false) === true,
					checkDuplicate: false,
				);
			} catch (ValidationException $e) {
				$errors[] = $this->l->t('Entry %1$d: %2$s', [$i + 1, $e->getMessage()]);
				continue;
			}
			$key = $holiday->getRegion() . '/' . $holiday->getDate();
			if (isset($seen[$key]) || $this->mapper->findByRegionAndDate($holiday->getRegion(), $holiday->getDate()) !== []) {
				$existing++;
				continue;
			}
			$seen[$key] = true;
			$new[] = ['date' => $holiday->getDate(), 'region' => $holiday->getRegion(), 'label' => $holiday->getLabel(), 'recurring' => $holiday->getRecurring()];
		}
		if ($errors !== []) {
			throw new ValidationException(implode("\n", array_slice($errors, 0, 20)));
		}
		return ['new' => $new, 'existing' => $existing];
	}

	/**
	 * @return array{new: list<array{date: string, region: string, label: string, recurring: bool}>, existing: int}
	 * @throws ValidationException
	 */
	public function import(string $json): array {
		$plan = $this->plan($json);
		$this->db->beginTransaction();
		try {
			foreach ($plan['new'] as $entry) {
				$holiday = new Holiday();
				$holiday->setDate($entry['date']);
				$holiday->setRegion($entry['region']);
				$holiday->setLabel($entry['label']);
				$holiday->setRecurring($entry['recurring']);
				$this->mapper->insert($holiday);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $plan;
	}

	/**
	 * The import format, so a list can move between instances or years.
	 *
	 * @return list<array{date: string, region: string, label: string, recurring: bool}>
	 */
	public function export(?int $year = null, ?string $region = null): array {
		$regions = $region === null ? null : [$this->normalizeRegion($region)];
		$holidays = $year === null
			? $this->mapper->findInRange('0001-01-01', '9999-12-31', $regions)
			: $this->mapper->findInRange("$year-01-01", "$year-12-31", $regions);
		return array_map(static fn (Holiday $h): array => [
			'date' => $h->getDate(),
			'region' => $h->getRegion(),
			'label' => $h->getLabel(),
			'recurring' => $h->getRecurring(),
		], $holidays);
	}

	/**
	 * `de-by` becomes `DE-BY`.
	 *
	 * @throws ValidationException
	 */
	public function normalizeRegion(string $region): string {
		$region = strtoupper(trim($region));
		if ($region !== self::EVERYWHERE && preg_match('/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', $region) !== 1) {
			throw new ValidationException($this->l->t('"%s" is not a region code: use a country like DE, a region like DE-BY, or * for everywhere', [$region]));
		}
		return $region;
	}

	/**
	 * @throws NotFoundException
	 */
	private function find(int $id): Holiday {
		try {
			return $this->mapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException($this->l->t('Holiday not found'));
		}
	}

	/**
	 * @throws ValidationException
	 */
	private function fill(Holiday $holiday, string $date, string $region, string $label, bool $recurring, bool $checkDuplicate = true): void {
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
			throw new ValidationException($this->l->t('"%s" is not a date like 2027-01-01', [$date]));
		}
		$label = trim($label);
		if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
			throw new ValidationException($this->l->t('The name must have between 1 and %d characters', [self::MAX_LABEL_LENGTH]));
		}
		$region = $this->normalizeRegion($region);
		if ($checkDuplicate) {
			foreach ($this->mapper->findByRegionAndDate($region, $date) as $other) {
				if ($other->getId() !== $holiday->getId()) {
					throw new ValidationException($this->l->t('%1$s already has a holiday on %2$s', [$region, $date]));
				}
			}
		}
		$holiday->setDate($date);
		$holiday->setRegion($region);
		$holiday->setLabel($label);
		$holiday->setRecurring($recurring);
	}

	/**
	 * Recurring holidays land on their day and month in every year of the range,
	 * from their own year on. 29 February only exists in leap years.
	 *
	 * @param list<Holiday> $holidays
	 * @return list<Holiday>
	 */
	private function expand(array $holidays, string $from, string $to): array {
		$days = [];
		foreach ($holidays as $holiday) {
			if (!$holiday->getRecurring()) {
				$days[] = $holiday;
				continue;
			}
			$monthDay = substr($holiday->getDate(), 5);
			$first = max((int)substr($holiday->getDate(), 0, 4), (int)substr($from, 0, 4));
			for ($year = $first; $year <= (int)substr($to, 0, 4); $year++) {
				$date = sprintf('%04d-%s', $year, $monthDay);
				if (!checkdate((int)substr($monthDay, 0, 2), (int)substr($monthDay, 3, 2), $year) || $date < $from || $date > $to) {
					continue;
				}
				$occurrence = clone $holiday;
				$occurrence->setDate($date);
				$days[] = $occurrence;
			}
		}
		usort($days, static fn (Holiday $a, Holiday $b): int => [$a->getDate(), $a->getRegion()] <=> [$b->getDate(), $b->getRegion()]);
		return $days;
	}
}
