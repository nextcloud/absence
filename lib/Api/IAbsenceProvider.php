<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Api;

/**
 * Lets other apps on the same server ask who is away, e.g. to plan duty rotas.
 *
 * Get it from the server container once the app is enabled:
 *
 *     if ($appManager->isEnabledForAnyone('absence')) {
 *         $absences = \OCP\Server::get(\OCA\Absence\Api\IAbsenceProvider::class);
 *     }
 *
 * Changes are announced with {@see \OCA\Absence\Event\LeaveRequestChangedEvent}.
 *
 * @since 1.1.0
 */
interface IAbsenceProvider {
	/**
	 * Absences of these users overlapping [$from, $to] (Y-m-d, both inclusive),
	 * ordered by start date. Rejected and cancelled requests are never returned.
	 *
	 * @param list<string> $userIds
	 * @param bool $includePending also return requests still waiting for a decision
	 * @return list<Absence>
	 * @since 1.1.0
	 */
	public function getAbsencesForUsers(array $userIds, string $from, string $to, bool $includePending = false): array;

	/**
	 * Absences of everyone overlapping [$from, $to] (Y-m-d, both inclusive),
	 * ordered by start date, e.g. to show who is away on a shared calendar.
	 *
	 * @param bool $includePending also return requests still waiting for a decision
	 * @return list<Absence>
	 * @since 1.1.0
	 */
	public function getAbsencesForEveryone(string $from, string $to, bool $includePending = false): array;

	/**
	 * When the user works and which public holidays apply to them, as this app
	 * uses them for its working-day estimate (§7.1): their Availability weekdays and
	 * holiday country/region, each overridable in the personal settings.
	 *
	 * `workWeekdays` are ISO weekdays (Mon=1..Sun=7), `holidayCountry` is an
	 * ISO 3166-1 alpha-2 code and `holidayRegion` follows the `date-holidays` data,
	 * e.g. `BY` for Bavaria. Both are null when not set.
	 *
	 * @return array{workWeekdays: list<int>, holidayCountry: ?string, holidayRegion: ?string}
	 * @since 1.1.0
	 */
	public function getWorkingDaysSettings(string $userId): array;
}
