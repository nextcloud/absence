<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Tests\Unit\Controller;

use OCA\Absence\Controller\RequestController;
use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Exception\ConflictException;
use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Exception\NotFoundException;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\RequestService;
use OCA\Absence\Tests\Unit\L10nMockTrait;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The controller is a thin shell around RequestService; what it owns is the
 * mapping of the domain's exceptions onto HTTP statuses and the shape of the
 * update() payload — both security-relevant, neither covered by the service
 * tests.
 */
class RequestControllerTest extends TestCase {
	use L10nMockTrait;

	private RequestService&MockObject $service;
	private RequestController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(RequestService::class);
		$this->controller = new RequestController(
			'absence',
			$this->createMock(IRequest::class),
			'emp',
			$this->service,
			$this->l10nMock(),
		);
	}

	private function request(): LeaveRequest {
		$request = new LeaveRequest();
		$request->setId(5);
		$request->setEmployeeUid('emp');
		return $request;
	}

	public function testCreatePassesTheActorAndPayloadThrough(): void {
		$this->service->expects(self::once())->method('create')
			->with('emp', self::callback(static function (array $data): bool {
				return $data['typeId'] === 1
					&& $data['startDate'] === '2026-09-01'
					&& $data['endDate'] === '2026-09-05'
					&& $data['workingDays'] === 5.0;
			}))
			->willReturn($this->request());

		$response = $this->controller->create(1, '2026-09-01', '2026-09-05', 5.0);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(5, $response->getData()['id']);
	}

	public function testAForbiddenDecisionIsA403(): void {
		$this->service->method('approve')->willThrowException(new ForbiddenException('no'));

		$response = $this->controller->approve(5);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('no', $response->getData()['message']);
	}

	public function testAValidationErrorIsA422(): void {
		$this->service->method('create')->willThrowException(new ValidationException('bad'));

		$response = $this->controller->create(1, 'nope', 'nope');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testAConflictIsA409(): void {
		$this->service->method('cancel')->willThrowException(new ConflictException('busy'));

		$response = $this->controller->cancel(5);

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}

	public function testAMissingRequestIsA404(): void {
		$this->service->method('getDetail')->willThrowException(new NotFoundException('gone'));

		$response = $this->controller->show(999);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testUpdateOmitsUnsetFieldsButPassesExplicitOnes(): void {
		// null means "not sent" for typeId/dates/workingDays, but reason,
		// attachmentNote and replacementUid may be intentionally cleared, so the
		// controller forwards them only when present.
		$this->service->expects(self::once())->method('update')
			->with('emp', 5, ['reason' => ''])
			->willReturn($this->request());

		$response = $this->controller->update(5, null, null, null, '');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testRejectRequiresACommentBySignature(): void {
		$this->service->expects(self::once())->method('reject')
			->with('emp', 5, 'too thin that week')
			->willReturn($this->request());

		$response = $this->controller->reject(5, 'too thin that week');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}
}
