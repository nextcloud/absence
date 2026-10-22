<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Command;

use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\LeaveImportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ absence:import-leave leave.json [--dry-run]`: past leave as approved,
 * HR-recorded leave (§6.5).
 */
class ImportLeave extends Command {
	public function __construct(
		private LeaveImportService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('absence:import-leave')
			->setDescription('Import past leave as approved, HR-recorded leave from a JSON file')
			->addArgument('file', InputArgument::REQUIRED, 'Path to the JSON file: a list of {"user", "type", "from", "to", "workingDays", "note"}')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and show what would be imported, without writing');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$path = (string)$input->getArgument('file');
		$json = is_readable($path) ? file_get_contents($path) : false;
		if ($json === false) {
			$output->writeln("<error>Cannot read $path</error>");
			return 1;
		}

		try {
			$preview = $this->service->preview($json);
		} catch (ValidationException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		foreach ($preview['people'] as $person) {
			$output->writeln(sprintf('  %-24s %4d entries %6s days %4d skipped', $person['uid'], $person['entries'], $person['days'], $person['skipped']));
		}
		if ($preview['errors'] !== []) {
			foreach ($preview['errors'] as $error) {
				$output->writeln(sprintf('<error>entry %d (%s): %s</error>', $error['entry'], $error['user'], $error['message']));
			}
			$output->writeln(sprintf('<error>Nothing was imported. Fix the invalid entries (%d) and try again.</error>', count($preview['errors'])));
			return 1;
		}

		if ($input->getOption('dry-run')) {
			$output->writeln(sprintf('<info>Dry run: %d entries to import, %d already there, nothing written.</info>', $preview['entries'], $preview['skipped']));
			return 0;
		}

		$result = $this->service->import($json, 'system');
		$output->writeln(sprintf('<info>Imported %d entries, skipped %d already there.</info>', $result['entries'], $result['skipped']));
		return 0;
	}
}
