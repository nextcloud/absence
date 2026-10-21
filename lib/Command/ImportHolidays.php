<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Command;

use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\HolidayService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ absence:import-holidays holidays.json [--dry-run]`
 *
 * Loads a year of public holidays (§7.2) in one validated, all-or-nothing pass.
 */
class ImportHolidays extends Command {
	public function __construct(
		private HolidayService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('absence:import-holidays')
			->setDescription('Import public holidays from a JSON file')
			->addArgument('file', InputArgument::REQUIRED, 'Path to a JSON list: [{"date": "2027-01-01", "region": "DE", "label": "New Year", "recurring": false}]')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and show what would be added, without writing');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$path = (string)$input->getArgument('file');
		$json = is_readable($path) ? file_get_contents($path) : false;
		if ($json === false) {
			$output->writeln("<error>Cannot read $path</error>");
			return 1;
		}
		$dryRun = (bool)$input->getOption('dry-run');
		try {
			$result = $dryRun ? $this->service->plan($json) : $this->service->import($json);
		} catch (ValidationException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		foreach ($result['new'] as $entry) {
			$output->writeln(sprintf('  %s %-7s %s%s', $entry['date'], $entry['region'], $entry['label'], $entry['recurring'] ? ' (every year)' : ''), OutputInterface::VERBOSITY_VERBOSE);
		}
		$output->writeln(sprintf(
			$dryRun ? '<info>Dry run: %d would be added, %d already there, nothing written.</info>' : '<info>%d added, %d already there.</info>',
			count($result['new']),
			$result['existing'],
		));
		return 0;
	}
}
