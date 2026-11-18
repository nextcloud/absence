<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Command;

use OCA\Absence\Service\RetentionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ absence:retention [--dry-run]`
 *
 * Deletes everything the retention settings (§12) no longer keep, at once
 * instead of a few batches a day. With --dry-run it only counts.
 */
class Retention extends Command {
	public function __construct(
		private RetentionService $retention,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('absence:retention')
			->setDescription('Delete the leave and the former employees the retention settings no longer keep')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count what would be deleted');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$leaveCutoff = $this->retention->leaveCutoff();
		$formerCutoff = $this->retention->formerEmployeeCutoff();
		if ($leaveCutoff === null && $formerCutoff === null) {
			$output->writeln('Both retention settings are 0: everything is kept.');
			return 0;
		}
		$output->writeln($leaveCutoff === null
			? 'Leave: kept forever.'
			: sprintf('Leave: what ended before %s goes.', $leaveCutoff));
		$output->writeln($formerCutoff === null
			? 'Former employees: kept forever.'
			: sprintf('Former employees: everything but the contract dates of those whose contract ended before %s goes.', $formerCutoff));

		$dryRun = (bool)$input->getOption('dry-run');
		$counts = $this->retention->apply($dryRun);
		$output->writeln($dryRun ? 'Would delete:' : 'Deleted:');
		$output->writeln(sprintf('  %d requests, with their comments and history', $counts['requests']));
		$output->writeln(sprintf('  %d attachments', $counts['attachments']));
		$output->writeln(sprintf('  %d entitlements', $counts['entitlements']));
		$output->writeln(sprintf('  %d former employees', $counts['people']));
		return 0;
	}
}
