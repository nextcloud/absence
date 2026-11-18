<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Command;

use OCA\Absence\Service\UserPurgeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ absence:purge-deleted-users [--force]`
 *
 * Lists the uids the Absence tables still name that no account has, and with
 * --force purges their data (§17), as if their account had just been deleted.
 */
class PurgeDeletedUsers extends Command {
	public function __construct(
		private UserPurgeService $purge,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('absence:purge-deleted-users')
			->setDescription('List, and with --force purge, the leave data of accounts that no longer exist')
			->setHelp(
				"Absence purges the data of an account when it is deleted, and retries daily when that fails.\n"
				. "Data stays behind when the app was disabled at the time. This lists the uids that Absence\n"
				. "still names and that no account has. Nothing is changed unless --force is given.\n\n"
				. "This never runs on its own: while a user backend such as LDAP cannot be reached, every\n"
				. 'account it holds looks deleted. Check that the listed uids are really gone before using --force.'
			)
			->addOption('force', null, InputOption::VALUE_NONE, 'Purge the data of the listed uids');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uids = $this->purge->findOrphanUids();
		if ($uids === []) {
			$output->writeln('Absence names no account that does not exist.');
			return 0;
		}
		if (!$input->getOption('force')) {
			$output->writeln('Absence still names these uids, which no account has:');
			foreach ($uids as $uid) {
				$output->writeln('  ' . $uid);
			}
			$output->writeln('Nothing was changed. Run again with --force to purge their data.');
			return 0;
		}
		$failed = 0;
		foreach ($uids as $uid) {
			try {
				$this->purge->purge($uid);
				$output->writeln('Purged ' . $uid);
			} catch (\Throwable $e) {
				$failed++;
				$output->writeln('<error>Could not purge ' . $uid . ': ' . $e->getMessage() . '</error>');
			}
		}
		return $failed === 0 ? 0 : 1;
	}
}
