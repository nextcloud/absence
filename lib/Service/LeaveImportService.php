<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Service;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Db\RequestEvent;
use OCA\Absence\Db\RequestEventMapper;
use OCA\Absence\Exception\ValidationException;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * JSON import of past leave (§6.5): the move from a shared calendar, where the
 * leave people already took has to land in the app as approved, HR-recorded
 * leave so their balances count it as used.
 *
 * Each entry is `{user, type?, from, to, workingDays, note?}`, `user` being a
 * uid or an e-mail address as in the entitlement import (§6.3). Like that
 * import it is all-or-nothing: one invalid entry and nothing is written.
 * Leave already in the app (same person, type and dates, still in force) is
 * skipped, so the same file can be imported twice.
 *
 * The leave already sits in the old calendar and its owners know about it, so
 * the import has no side effects: no notification, e-mail, activity, CalDAV
 * write or {@see \OCA\Absence\Event\LeaveRequestChangedEvent}. Each request
 * gets a `request_imported` history event and the whole import one entry in
 * the server log.
 */
class LeaveImportService {
	use DateRangeTrait;
	use WorkingDaysGuardTrait;

	public const MAX_ENTRIES = 5000;
	private const MAX_NOTE_LENGTH = 2000;

	public function __construct(
		private ImportService $importService,
		private EmployeeDirectory $employees,
		private LeaveTypeMapper $leaveTypeMapper,
		private LeaveRequestMapper $requestMapper,
		private RequestEventMapper $eventMapper,
		private ManagerResolver $managerResolver,
		private IUserManager $userManager,
		private ClockService $clock,
		private IL10N $l,
		private LoggerInterface $logger,
		private IDBConnection $db,
	) {
	}

	/**
	 * Validate the whole list without writing anything: what would be added per
	 * person, what is skipped as already there, and every invalid entry with its
	 * 1-based position. While `errors` is not empty, {@see import()} refuses to write.
	 *
	 * @return array{entries: int, days: float, skipped: int, people: list<array{uid: string, name: string, entries: int, days: float, skipped: int}>, errors: list<array{entry: int, user: string, message: string}>}
	 * @throws ValidationException when the input is not a list of entries at all
	 */
	public function preview(string $json): array {
		return $this->analyse($json)[0];
	}

	/**
	 * @return array{0: array{entries: int, days: float, skipped: int, people: list<array{uid: string, name: string, entries: int, days: float, skipped: int}>, errors: list<array{entry: int, user: string, message: string}>}, 1: list<array{uid: string, type: LeaveType, from: string, to: string, workingDays: float, note: ?string}>}
	 * @throws ValidationException
	 */
	private function analyse(string $json): array {
		$entries = $this->decode($json);
		$types = [];
		foreach ($this->leaveTypeMapper->findAll() as $type) {
			$types[$type->getKey()] = $type;
		}

		$plan = [];
		$errors = [];
		$people = [];
		/** @var array<string, array<string, true>> $existing uid => set of type/from/to already in force */
		$existing = [];
		foreach ($entries as $i => $entry) {
			$user = is_array($entry) && is_scalar($entry['user'] ?? null) ? trim((string)$entry['user']) : '';
			try {
				$row = $this->validate($entry, $types);
			} catch (ValidationException $e) {
				$errors[] = ['entry' => $i + 1, 'user' => $user, 'message' => $e->getMessage()];
				continue;
			}
			$uid = $row['uid'];
			if (!isset($existing[$uid])) {
				$existing[$uid] = [];
				foreach ($this->requestMapper->findAllForEmployee($uid) as $request) {
					if (in_array($request->getStatus(), LeaveRequest::ACTIVE_STATUSES, true)) {
						$existing[$uid][$this->key($request->getTypeId(), $request->getStartDate(), $request->getEndDate())] = true;
					}
				}
				$people[$uid] = ['uid' => $uid, 'name' => $this->displayName($uid), 'entries' => 0, 'days' => 0.0, 'skipped' => 0];
			}
			$key = $this->key((int)$row['type']->getId(), $row['from'], $row['to']);
			if (isset($existing[$uid][$key])) {
				$people[$uid]['skipped']++;
				continue;
			}
			// A later copy of the same entry in this file is a duplicate as well
			$existing[$uid][$key] = true;
			$people[$uid]['entries']++;
			$people[$uid]['days'] += $row['workingDays'];
			$plan[] = $row;
		}

		$people = array_values($people);
		usort($people, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
		return [[
			'entries' => count($plan),
			'days' => (float)array_sum(array_column($people, 'days')),
			'skipped' => (int)array_sum(array_column($people, 'skipped')),
			'people' => $people,
			'errors' => $errors,
		], $plan];
	}

	/**
	 * Book every valid entry as approved leave, in one transaction.
	 *
	 * @param string $actorUid the HR member importing, or `system` from occ
	 * @return array{entries: int, days: float, skipped: int, people: list<array{uid: string, name: string, entries: int, days: float, skipped: int}>, errors: list<array{entry: int, user: string, message: string}>} what was imported, as {@see preview()} reports it
	 * @throws ValidationException if any entry is invalid; nothing is written then
	 */
	public function import(string $json, string $actorUid): array {
		[$preview, $plan] = $this->analyse($json);
		if ($preview['errors'] !== []) {
			throw new ValidationException($this->l->n(
				'Nothing was imported: %n entry is invalid.',
				'Nothing was imported: %n entries are invalid.',
				count($preview['errors']),
			));
		}

		$now = $this->clock->now();
		$managers = [];
		$this->db->beginTransaction();
		try {
			foreach ($plan as $row) {
				$uid = $row['uid'];
				if (!array_key_exists($uid, $managers)) {
					$managers[$uid] = $this->managerResolver->getManagerUid($uid);
				}
				$request = new LeaveRequest();
				$request->setEmployeeUid($uid);
				$request->setManagerUid($managers[$uid]);
				$request->setTypeId((int)$row['type']->getId());
				$request->setStartDate($row['from']);
				$request->setEndDate($row['to']);
				$request->setWorkingDays($row['workingDays']);
				$request->setReason($row['note']);
				$request->setStatus(LeaveRequest::STATUS_APPROVED);
				$request->setDecidedBy($actorUid);
				$request->setDecidedAt($now);
				$request->setEscalated(false);
				$request->setCreatedAt($now);
				$request->setUpdatedAt($now);
				$request = $this->requestMapper->insert($request);

				$event = new RequestEvent();
				$event->setRequestId((int)$request->getId());
				$event->setActorUid($actorUid);
				$event->setEventType('request_imported');
				$event->setDetail($this->detail($row));
				$event->setCreatedAt($now);
				$this->eventMapper->insert($event);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->logger->info('Absence action: leave_imported', [
			'app' => 'absence',
			'action' => 'leave_imported',
			'actor' => $actorUid,
			'entries' => $preview['entries'],
			'skipped' => $preview['skipped'],
			'people' => count(array_filter($preview['people'], static fn (array $p): bool => $p['entries'] > 0)),
			'workingDays' => $preview['days'],
		]);
		return $preview;
	}

	/**
	 * @return list<mixed>
	 * @throws ValidationException
	 */
	private function decode(string $json): array {
		try {
			$entries = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new ValidationException($this->l->t('This is not valid JSON: %s', [$e->getMessage()]));
		}
		if (!is_array($entries) || !array_is_list($entries) || $entries === []) {
			throw new ValidationException($this->l->t('Expected a list of leave, like [{"user": "alice", "from": "2026-03-02", "to": "2026-03-06", "workingDays": 5}]'));
		}
		if (count($entries) > self::MAX_ENTRIES) {
			throw new ValidationException($this->l->t('At most %d entries per import', [self::MAX_ENTRIES]));
		}
		return $entries;
	}

	/**
	 * @param array<string, LeaveType> $types by key
	 * @return array{uid: string, type: LeaveType, from: string, to: string, workingDays: float, note: ?string}
	 * @throws ValidationException
	 */
	private function validate(mixed $entry, array $types): array {
		if (!is_array($entry)) {
			throw new ValidationException($this->l->t('Not an object'));
		}
		$user = is_scalar($entry['user'] ?? null) ? trim((string)$entry['user']) : '';
		$uid = $this->importService->resolveUser($user);
		if ($uid === null) {
			throw new ValidationException($this->l->t('Unknown user "%s"', [$user]));
		}
		if (!$this->employees->isEmployee($uid)) {
			throw new ValidationException($this->l->t('"%s" is not an employee', [$uid]));
		}

		$key = is_string($entry['type'] ?? null) && trim($entry['type']) !== '' ? trim($entry['type']) : 'annual';
		$type = $types[$key] ?? null;
		if ($type === null || !$type->getEnabled()) {
			throw new ValidationException($this->l->t('Unknown leave type "%s"', [$key]));
		}

		[$from, $to] = $this->assertValidRange(
			is_string($entry['from'] ?? null) ? $entry['from'] : '',
			is_string($entry['to'] ?? null) ? $entry['to'] : '',
		);
		$workingDays = $this->normaliseWorkingDays($entry['workingDays'] ?? null);
		$this->assertWorkingDaysFitRange($workingDays, $from, $to);

		$note = is_string($entry['note'] ?? null) ? trim($entry['note']) : '';
		if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
			throw new ValidationException($this->l->t('The note is too long.'));
		}

		return ['uid' => $uid, 'type' => $type, 'from' => $from, 'to' => $to, 'workingDays' => $workingDays, 'note' => $note !== '' ? $note : null];
	}

	private function key(int $typeId, string $from, string $to): string {
		return $typeId . '/' . $from . '/' . $to;
	}

	/**
	 * @param array{uid: string, type: LeaveType, from: string, to: string, workingDays: float, note: ?string} $row
	 */
	private function detail(array $row): string {
		$days = rtrim(rtrim(number_format($row['workingDays'], 1, '.', ''), '0'), '.');
		$range = $row['from'] === $row['to'] ? $row['from'] : $row['from'] . ' – ' . $row['to'];
		$parts = [$row['type']->getLabel() . ', ' . $range . ' (' . $days . ($days === '1' ? ' day' : ' days') . ')'];
		if ($row['note'] !== null) {
			$parts[] = 'Note: “' . $row['note'] . '”';
		}
		$parts[] = 'Imported by HR';
		return implode(' · ', $parts);
	}

	private function displayName(string $uid): string {
		return $this->userManager->get($uid)?->getDisplayName() ?? $uid;
	}
}
