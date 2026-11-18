<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Migration;

use Closure;
use OCA\Absence\Service\ConfigService;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Deletes the team calendar that earlier versions stored under the
 * `principals/system/absence` principal and shared with the employees and HR
 * groups, now that every employee and HR member gets one built in their own
 * calendar home (§10). It goes for good, with its events, those in the trash
 * bin and its shares. The references to its events on the requests, the job
 * that wrote them and the job's cursor go with it.
 */
class Version1019Date20261118120000 extends SimpleMigrationStep {
	private const PRINCIPAL = 'principals/system/absence';
	private const CALENDAR_URI = 'absence-team';
	private const SYNC_JOB = 'OCA\Absence\BackgroundJob\CalendarSyncJob';
	private const SYNC_CURSOR = 'calendar_sync_cursor';

	public function __construct(
		private IDBConnection $db,
		private ContainerInterface $container,
		private IJobList $jobList,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$calendarId = $this->storedCalendarId();
		if ($calendarId !== null) {
			$this->deleteCalendar($calendarId);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('absence_requests')
			->set('calendar_event_uri', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->isNotNull('calendar_event_uri'));
		$qb->executeStatement();

		$this->jobList->remove(self::SYNC_JOB);
		$this->appConfig->deleteKey(ConfigService::APP_ID, self::SYNC_CURSOR);
	}

	private function storedCalendarId(): ?int {
		if (!$this->db->tableExists('calendars')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('calendars')
			->where($qb->expr()->eq('principaluri', $qb->createNamedParameter(self::PRINCIPAL)))
			->andWhere($qb->expr()->eq('uri', $qb->createNamedParameter(self::CALENDAR_URI)));
		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();
		return $id === false ? null : (int)$id;
	}

	private function deleteCalendar(int $calendarId): void {
		try {
			$backend = $this->container->get(CalDavBackend::class);
		} catch (\Throwable $e) {
			$this->logger->warning('Absence: the stored team calendar could not be deleted, the dav app is not available', ['exception' => $e]);
			return;
		}
		// Moved to the trash bin first: the dav app then deletes it without an
		// activity for every member of the groups it was shared with.
		$qb = $this->db->getQueryBuilder();
		$qb->update('calendars')
			->set('deleted_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$qb->executeStatement();
		$backend->deleteCalendar($calendarId, true);
	}
}
