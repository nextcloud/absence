<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\BackgroundJob;

/**
 * The escalation/reminder windows are configured in *working days* (§5.4). The app
 * has no holiday data (§7), so working days are approximated as Monday–Friday.
 */
trait WorkingDaysTrait {
	private function subtractWorkingDays(\DateTimeImmutable $from, int $workingDays): \DateTimeImmutable {
		$date = $from;
		while ($workingDays > 0) {
			$date = $date->modify('-1 day');
			if ((int)$date->format('N') <= 5) {
				$workingDays--;
			}
		}
		return $date;
	}
}
