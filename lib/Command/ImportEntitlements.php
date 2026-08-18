<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Command;

use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\ImportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ absence:import-entitlements balances.csv [--year 2026] [--dry-run]`
 *
 * The onboarding path (§6.3): bring current entitlements over from the
 * spreadsheet in one validated, audited, all-or-nothing pass.
 */
class ImportEntitlements extends Command {
	public function __construct(
		private ImportService $importService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('absence:import-entitlements')
			->setDescription('Import entitlements (base days, carry-over, adjustments) from a CSV file')
			->addArgument('file', InputArgument::REQUIRED, 'Path to the CSV file (columns: user, base_days, carry_over_days, adjustment, note, type, year)')
			->addOption('year', null, InputOption::VALUE_REQUIRED, 'Default year for rows without a year column', date('Y'))
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and show what would be written, without writing');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$path = (string)$input->getArgument('file');
		$csv = is_readable($path) ? file_get_contents($path) : false;
		if ($csv === false) {
			$output->writeln("<error>Cannot read $path</error>");
			return 1;
		}

		try {
			$plan = $this->importService->plan($csv, (int)$input->getOption('year'));
		} catch (ValidationException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		foreach ($plan as $row) {
			$parts = [];
			foreach (['baseDays' => 'base', 'carryOverDays' => 'carry-over', 'manualAdjustment' => 'adjustment'] as $key => $label) {
				if (isset($row['data'][$key])) {
					$parts[] = $label . ' ' . $row['data'][$key];
				}
			}
			$output->writeln(sprintf('  %-24s %d/type %d: %s', $row['uid'], $row['year'], $row['typeId'], implode(', ', $parts)));
		}

		if ($input->getOption('dry-run')) {
			$output->writeln(sprintf('<info>Dry run: %d rows validated, nothing written.</info>', count($plan)));
			return 0;
		}

		$applied = $this->importService->apply($plan, 'import:cli');
		$output->writeln(sprintf('<info>Imported %d entitlement rows.</info>', $applied));
		return 0;
	}
}
