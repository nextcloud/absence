<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Activity;

use OCA\Absence\Activity\Provider;
use OCA\Absence\Service\ActivityPublisher;
use OCA\Absence\Service\ConfigService;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProviderTest extends TestCase {
	private IURLGenerator&MockObject $urlGenerator;
	private IUserManager&MockObject $userManager;
	private Provider $provider;

	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->provider = new Provider($l10nFactory, $this->urlGenerator, $this->userManager);
	}

	public function testParseThrowsUnknownActivityForForeignApp(): void {
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn('files');

		$this->expectException(UnknownActivityException::class);
		$this->provider->parse('en', $event);
	}

	public function testParseThrowsUnknownActivityForUnknownSubject(): void {
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn(ConfigService::APP_ID);
		$event->method('getSubject')->willReturn('something_else');
		$event->method('getSubjectParameters')->willReturn([]);

		$this->expectException(UnknownActivityException::class);
		$this->provider->parse('en', $event);
	}

	public function testParseSetsSubjectIconAndLink(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice Doe');
		$this->userManager->method('get')->with('alice')->willReturn($user);

		$this->urlGenerator->method('imagePath')->willReturn('/img/app-dark.svg');
		$this->urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example.com/img/app-dark.svg');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example.com/apps/absence/');

		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn(ConfigService::APP_ID);
		$event->method('getSubject')->willReturn(ActivityPublisher::SUBJECT_APPROVED);
		$event->method('getSubjectParameters')->willReturn([
			'employee' => 'alice',
			'start' => '2026-08-03',
			'end' => '2026-08-07',
		]);
		$event->method('getObjectId')->willReturn(42);

		$event->expects($this->once())
			->method('setParsedSubject')
			->with('Leave for Alice Doe (2026-08-03 – 2026-08-07) was approved');
		$event->expects($this->once())
			->method('setIcon')
			->with('https://cloud.example.com/img/app-dark.svg');
		$event->expects($this->once())
			->method('setLink')
			->with('https://cloud.example.com/apps/absence/#/requests/42');

		$this->assertSame($event, $this->provider->parse('en', $event));
	}

	public function testParseRendersAnUpdateAsAnUpdateNotACreation(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice Doe');
		$this->userManager->method('get')->with('alice')->willReturn($user);
		$this->urlGenerator->method('imagePath')->willReturn('/img/app-dark.svg');
		$this->urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example.com/img/app-dark.svg');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example.com/apps/absence/');

		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn(ConfigService::APP_ID);
		$event->method('getSubject')->willReturn(ActivityPublisher::SUBJECT_UPDATED);
		$event->method('getSubjectParameters')->willReturn([
			'employee' => 'alice',
			'start' => '2026-08-03',
			'end' => '2026-08-07',
		]);
		$event->method('getObjectId')->willReturn(42);

		$event->expects($this->once())
			->method('setParsedSubject')
			->with('Leave for Alice Doe (2026-08-03 – 2026-08-07) was updated');

		$this->assertSame($event, $this->provider->parse('en', $event));
	}
}
