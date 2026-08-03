<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Settings;

use OCA\Absence\Service\PersonalDefaultsService;
use OCA\Absence\Settings\Personal;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PersonalTest extends TestCase {
	private IUserSession&MockObject $userSession;
	private IAppManager&MockObject $appManager;
	private Personal $form;

	protected function setUp(): void {
		parent::setUp();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->form = new Personal(
			$this->createMock(IInitialState::class),
			$this->createMock(PersonalDefaultsService::class),
			$this->userSession,
			$this->appManager,
		);
	}

	private function loginUser(): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}

	public function testSectionIsAvailabilityWhenAppIsEnabledForTheUser(): void {
		$user = $this->loginUser();
		$this->appManager->expects($this->once())
			->method('isEnabledForUser')
			->with('absence', $user)
			->willReturn(true);

		$this->assertSame('availability', $this->form->getSection());
	}

	public function testNoSectionWhenAppIsNotEnabledForTheUser(): void {
		$this->loginUser();
		$this->appManager->method('isEnabledForUser')->willReturn(false);

		$this->assertNull($this->form->getSection());
	}

	public function testNoSectionWithoutAUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->appManager->expects($this->never())->method('isEnabledForUser');

		$this->assertNull($this->form->getSection());
	}
}
