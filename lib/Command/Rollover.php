<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Command;

use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\EntitlementService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ absence:rollover 2026`
 *
 * Runs the year rollover (§6.2) by hand: carry-over from the given year into the
 * next one. It recomputes from the given year, so it can be repeated.
 */
class Rollover extends Command {
	public function __construct(
		private EntitlementService $entitlementService,
		private ClockService $clock,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('absence:rollover')
			->setDescription('Recompute the carry-over from a finished year into the next one')
			->addArgument('year', InputArgument::REQUIRED, 'The year to carry over from, e.g. 2026 to fill in 2027');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$year = (int)$input->getArgument('year');
		if ($year < 2000 || $year >= $this->clock->serverYear()) {
			$output->writeln('<error>The year must be a finished year, 2000 or later.</error>');
			return 1;
		}

		$result = $this->entitlementService->rollover($year);
		// The rollover refills carry-over the expiry already reduced: reduce it again.
		$this->entitlementService->expireCarryOver($year + 1);

		$output->writeln(sprintf(
			'Carry-over from %d into %d: %d entitlements written, %d left alone (carry-over set by hand), %d failed.',
			$year, $year + 1, $result['affected'], $result['skipped'], $result['failed'],
		));
		if ($result['failed'] > 0) {
			$output->writeln('<error>Some entitlements failed, see nextcloud.log. Running the command again is safe.</error>');
			return 1;
		}
		return 0;
	}
}
