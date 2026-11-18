<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\UserMigration;

use OCA\Absence\Db\AttachmentMapper;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveType;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\AttachmentService;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\ProfileService;
use OCA\Absence\Service\RequestService;
use OCP\IL10N;
use OCP\IUser;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\IMigrator;
use OCP\UserMigration\ISizeEstimationMigrator;
use OCP\UserMigration\TMigratorBasicVersionHandling;
use OCP\UserMigration\UserMigrationException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The person's own Absence data in their personal data export (§17), as they
 * see it in the app: what the API withholds from them, such as the content of
 * a confidential absence (§5.7), the disability flag (§5.8) or the entitlement
 * history, is withheld here too. HR answers for that part.
 *
 * Nothing is imported: leave records belong to the employer's instance.
 */
class AbsenceMigrator implements IMigrator, ISizeEstimationMigrator {
	use TMigratorBasicVersionHandling;

	private const PATH_ROOT = ConfigService::APP_ID . '/';
	/** What the request detail says about the viewer's possible actions, not about the request */
	private const ACTION_KEYS = ['canDecide', 'canModify', 'canAttach', 'coverage', 'shortNotice', 'balance'];

	public function __construct(
		private LeaveRequestMapper $requestMapper,
		private RequestService $requests,
		private AttachmentMapper $attachmentMapper,
		private AttachmentService $attachments,
		private BalanceService $balances,
		private LeaveTypeMapper $leaveTypeMapper,
		private PermissionService $permission,
		private ProfileService $profiles,
		private ConfigService $config,
		private IL10N $l,
	) {
	}

	#[\Override]
	public function getEstimatedExportSize(IUser $user): int|float {
		// KiB: the JSON files, and every attached file at most
		return 100 + (int)ceil($this->attachmentMapper->sumSizeForEmployee($user->getUID()) / 1024);
	}

	#[\Override]
	public function export(IUser $user, IExportDestination $exportDestination, OutputInterface $output): void {
		$output->writeln('Exporting Absence data in ' . self::PATH_ROOT . '…');
		$uid = $user->getUID();
		try {
			$requests = [];
			foreach ($this->requestMapper->findAllForEmployee($uid) as $request) {
				$detail = array_diff_key($this->requests->getDetail($uid, $request->getId()), array_flip(self::ACTION_KEYS));
				foreach ($detail['attachments'] as $attachment) {
					$file = $this->attachments->download($uid, (int)$attachment['id']);
					$exportDestination->addFileContents(self::PATH_ROOT . 'attachments/' . $attachment['id'] . '/' . $file['attachment']->getName(), $file['content']);
				}
				$requests[] = $detail;
			}
			$profile = $this->profiles->get($uid, $uid);
			$balance = $this->balances->getBalance($uid);
			$files = [
				'requests.json' => $requests,
				'leave-types.json' => array_map(
					static fn (LeaveType $type): array => $type->jsonSerialize(),
					$this->permission->filterVisibleTypes($uid, $this->leaveTypeMapper->findAll()),
				),
				'balances.json' => $this->permission->filterVisibleBalanceRows($uid, $balance['balances']),
				'contract.json' => ['contract' => $profile['contract'], 'history' => $profile['history']],
				'settings.json' => $this->config->getPersonalConfig($uid),
			];
			foreach ($files as $name => $data) {
				$exportDestination->addFileContents(self::PATH_ROOT . $name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
			}
		} catch (\Throwable $e) {
			throw new UserMigrationException('Could not export the Absence data', 0, $e);
		}
	}

	#[\Override]
	public function import(IUser $user, IImportSource $importSource, OutputInterface $output): void {
		$output->writeln('Absence data is not imported: leave records belong to the instance of the employer who keeps them.');
	}

	#[\Override]
	public function getId(): string {
		return ConfigService::APP_ID;
	}

	#[\Override]
	public function getDisplayName(): string {
		return $this->l->t('Absence');
	}

	#[\Override]
	public function getDescription(): string {
		return $this->l->t('Leave requests with their comments, history and attachments, days per year, contract and region code');
	}
}
