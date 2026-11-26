<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use Closure;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes the personal calendars that versions up to 1.0.12 stored in the
 * calendar homes of employees, now that each of them gets one built from the
 * leave itself (§10). Left in place, the stored copy shows every leave a
 * second time and never follows a cancellation.
 *
 * Only what 1.0.12 wrote goes: calendars with the uri `absence`, and in them
 * the events named `absence-<request id>-<8 hex>.ics` (`-deleted.ics` in the
 * trash bin). A calendar holding
 * nothing else is deleted for good; one with events of the person's own keeps
 * them and loses only the leave. An empty one is deleted only when it still
 * has the name 1.0.12 gave it.
 */
class Version1024Date20261126150000 extends SimpleMigrationStep {
	private const PRINCIPAL_PREFIX = 'principals/users/';
	private const CALENDAR_URI = 'absence';
	private const DISPLAY_NAME = 'Absence';
	private const EVENT_URI = '/^absence-\d+-[0-9a-f]{8}(-deleted)?\.ics$/';

	public function __construct(
		private IDBConnection $db,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->db->tableExists('calendars')) {
			return;
		}
		$calendars = $this->storedCalendars();
		if ($calendars === []) {
			return;
		}
		try {
			$backend = $this->container->get(CalDavBackend::class);
		} catch (\Throwable $e) {
			$this->logger->warning('Absence: the stored personal calendars could not be removed, the dav app is not available', ['exception' => $e]);
			return;
		}

		foreach ($calendars as $calendar) {
			$events = $this->eventUris($calendar['id']);
			$leave = array_values(array_filter($events, static fn (string $uri): bool => preg_match(self::EVENT_URI, $uri) === 1));
			if ($events === [] && $calendar['displayname'] !== self::DISPLAY_NAME) {
				continue;
			}
			if (count($leave) === count($events)) {
				$this->moveToTrashBin($calendar['id']);
				$backend->deleteCalendar($calendar['id'], true);
				continue;
			}
			foreach ($leave as $uri) {
				$backend->deleteCalendarObject($calendar['id'], $uri, CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
			}
			$this->logger->info('Absence: kept the stored calendar of {principal}, it holds events of their own', ['principal' => $calendar['principaluri']]);
		}
	}

	/**
	 * @return list<array{id: int, principaluri: string, displayname: ?string}>
	 */
	private function storedCalendars(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'principaluri', 'displayname')
			->from('calendars')
			->where($qb->expr()->eq('uri', $qb->createNamedParameter(self::CALENDAR_URI)))
			->andWhere($qb->expr()->like('principaluri', $qb->createNamedParameter($this->db->escapeLikeParameter(self::PRINCIPAL_PREFIX) . '%')));
		$result = $qb->executeQuery();
		$calendars = [];
		while ($row = $result->fetch()) {
			$calendars[] = [
				'id' => (int)$row['id'],
				'principaluri' => (string)$row['principaluri'],
				'displayname' => $row['displayname'] === null ? null : (string)$row['displayname'],
			];
		}
		$result->closeCursor();
		return $calendars;
	}

	/**
	 * The calendar's event uris, those in the trash bin included
	 *
	 * @return list<string>
	 */
	private function eventUris(int $calendarId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uri')
			->from('calendarobjects')
			->where($qb->expr()->eq('calendarid', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('calendartype', $qb->createNamedParameter(CalDavBackend::CALENDAR_TYPE_CALENDAR, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$uris = [];
		while (($uri = $result->fetchOne()) !== false) {
			$uris[] = (string)$uri;
		}
		$result->closeCursor();
		return $uris;
	}

	/**
	 * As for the team calendar (Version1019…): a calendar in the trash bin is
	 * deleted by the dav app without an activity for anybody it was shared with.
	 */
	private function moveToTrashBin(int $calendarId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('calendars')
			->set('deleted_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$qb->executeStatement();
	}
}
