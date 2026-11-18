<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\BackgroundJob;

use OCA\Absence\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Deletes what the retention settings no longer keep (§9), a bounded number of
 * batches a day. Does nothing while both settings are 0.
 */
class RetentionJob extends TimedJob {
	/** Batches of each rule per run: a long backlog takes a few days, or one `occ absence:retention` */
	private const MAX_BATCHES = 50;

	public function __construct(
		ITimeFactory $time,
		private RetentionService $retention,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		$this->retention->apply(maxBatches: self::MAX_BATCHES);
	}
}
