<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Command;

use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\EmployeeJsonService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ absence:import-employees employees.json [--dry-run]`
 *
 * Sets the region code, start and end date and contract of the employees in the
 * list (§6.5), all of them or none.
 */
class ImportEmployees extends Command {
	public function __construct(
		private EmployeeJsonService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('absence:import-employees')
			->setDescription('Set the region code, start and end date and contract of employees from a JSON file')
			->addArgument('file', InputArgument::REQUIRED, 'Path to a JSON list, as absence:export-employees prints it')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check the file and show what would change, without writing');
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
		$plan = $this->service->plan($json);
		foreach ($plan['errors'] as $error) {
			$where = $error['line'] !== null ? 'Line ' . $error['line'] : ($error['entry'] !== null ? 'Entry ' . $error['entry'] : 'File');
			$output->writeln("<error>$where: {$error['message']}</error>");
		}
		if ($plan['errors'] !== []) {
			$output->writeln(sprintf('<error>Nothing was saved: %d entries are wrong.</error>', count($plan['errors'])));
			return 1;
		}
		if (!$dryRun) {
			try {
				$plan = $this->service->import($json, null);
			} catch (ValidationException $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				return 1;
			}
		}
		foreach ($plan['changes'] as $change) {
			$output->writeln(sprintf('  %s (%s): %s', $change['name'], $change['user'], implode(', ', array_column($change['fields'], 'field'))));
		}
		$output->writeln(sprintf(
			$dryRun ? '<info>Dry run: %d employees would change, %d stay the same, nothing written.</info>' : '<info>%d employees changed, %d stay the same.</info>',
			count($plan['changes']),
			$plan['unchanged'],
		));
		return 0;
	}
}
