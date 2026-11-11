<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Controller;

use OCA\Absence\Controller\EntitlementController;
use OCA\Absence\Db\EntitlementEvent;
use OCA\Absence\Service\EntitlementService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\ProRataService;
use OCA\Absence\Tests\Unit\L10nMockTrait;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EntitlementControllerTest extends TestCase {
	use L10nMockTrait;

	public function testHistoryNamesWhoMadeEachChange(): void {
		$events = [];
		foreach (['itest-hr', 'itest-gone', 'itest-hr', null] as $actorUid) {
			$event = new EntitlementEvent();
			$event->setActorUid($actorUid);
			$events[] = $event;
		}
		$service = $this->createMock(EntitlementService::class);
		$service->method('historyFor')->with(7)->willReturn($events);
		$userManager = $this->createMock(IUserManager::class);
		// One lookup per person, however many changes they made.
		$userManager->expects(self::exactly(2))->method('getDisplayName')->willReturnCallback(
			static fn (string $uid): ?string => $uid === 'itest-hr' ? 'Robin Vale' : null,
		);
		$controller = new EntitlementController(
			'absence',
			$this->createMock(IRequest::class),
			'itest-hr',
			$service,
			$this->createMock(ProRataService::class),
			$this->createMock(PermissionService::class),
			$this->l10nMock(),
			$this->createMock(LoggerInterface::class),
			$userManager,
		);

		$history = $controller->history(7)->getData();

		// An account that no longer exists keeps its uid as the name, and a
		// change made without a user has none.
		self::assertSame(
			['Robin Vale', 'itest-gone', 'Robin Vale', null],
			array_map(static fn (array $event): ?string => $event['actorName'], $history),
		);
	}
}
