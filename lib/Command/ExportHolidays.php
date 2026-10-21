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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ absence:export-holidays [--year 2027] [--region DE-BY]`, in the import format.
 */
class ExportHolidays extends Command {
	public function __construct(
		private HolidayService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('absence:export-holidays')
			->setDescription('Print the public holidays as JSON, in the format absence:import-holidays reads')
			->addOption('year', null, InputOption::VALUE_REQUIRED, 'Only this year')
			->addOption('region', null, InputOption::VALUE_REQUIRED, 'Only this region code');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$year = $input->getOption('year');
		$region = $input->getOption('region');
		try {
			$holidays = $this->service->export($year === null ? null : (int)$year, $region === null ? null : (string)$region);
		} catch (ValidationException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln(json_encode($holidays, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		return 0;
	}
}
