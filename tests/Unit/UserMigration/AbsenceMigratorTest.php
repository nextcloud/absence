<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\UserMigration;

use OCA\Absence\Db\AttachmentMapper;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\AttachmentService;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ConfigService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\ProfileService;
use OCA\Absence\Service\RequestService;
use OCA\Absence\Tests\Unit\L10nMockTrait;
use OCA\Absence\UserMigration\AbsenceMigrator;
use OCP\IUser;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\UserMigrationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class AbsenceMigratorTest extends TestCase {
	use L10nMockTrait;

	/** @var list<MockObject> */
	private array $services = [];
	private LeaveRequestMapper&MockObject $requestMapper;
	private AttachmentMapper&MockObject $attachmentMapper;
	private IUser&MockObject $user;
	private AbsenceMigrator $migrator;

	protected function setUp(): void {
		parent::setUp();
		$this->requestMapper = $this->createMock(LeaveRequestMapper::class);
		$this->attachmentMapper = $this->createMock(AttachmentMapper::class);
		$this->services = [
			$this->requestMapper,
			$this->createMock(RequestService::class),
			$this->attachmentMapper,
			$this->createMock(AttachmentService::class),
			$this->createMock(BalanceService::class),
			$this->createMock(LeaveTypeMapper::class),
			$this->createMock(PermissionService::class),
			$this->createMock(ProfileService::class),
			$this->createMock(ConfigService::class),
		];
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('alice');
		$this->migrator = new AbsenceMigrator(...[...$this->services, $this->l10nMock()]);
	}

	public function testTheImportSaysSoAndTouchesNothing(): void {
		foreach ($this->services as $service) {
			$service->expects(self::never())->method(self::anything());
		}
		$source = $this->createMock(IImportSource::class);
		$source->expects(self::never())->method(self::anything());
		$output = new BufferedOutput();

		$this->migrator->import($this->user, $source, $output);

		self::assertStringContainsString('Absence data is not imported', $output->fetch());
	}

	public function testAFailedExportFailsAsAMigrationError(): void {
		$this->requestMapper->method('findAllForEmployee')->willThrowException(new \RuntimeException('database gone'));

		$this->expectException(UserMigrationException::class);
		$this->migrator->export($this->user, $this->createMock(IExportDestination::class), new BufferedOutput());
	}

	public function testTheSizeCountsTheAttachedFiles(): void {
		$this->attachmentMapper->method('sumSizeForEmployee')->with('alice')->willReturn(3 * 1024 * 1024 + 1);

		self::assertEquals(100 + 3 * 1024 + 1, $this->migrator->getEstimatedExportSize($this->user));
	}
}
