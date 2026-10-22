<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Command;

use OCA\Absence\Service\EmployeeJsonService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ absence:export-employees`, in the format absence:import-employees reads (§6.5).
 */
class ExportEmployees extends Command {
	public function __construct(
		private EmployeeJsonService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('absence:export-employees')
			->setDescription('Print every employee\'s region code, start and end date and contract as JSON, in the format absence:import-employees reads');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->write(EmployeeJsonService::format($this->service->export()), false, OutputInterface::OUTPUT_RAW);
		return 0;
	}
}
