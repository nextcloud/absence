<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Service\RequestService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

class RequestController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private RequestService $service,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(
		string $scope = 'mine',
		?string $status = null,
		?int $typeId = null,
		?string $from = null,
		?string $to = null,
		?string $employeeUid = null,
		int $limit = 200,
		int $offset = 0,
	): DataResponse {
		return $this->handle(fn () => $this->service->listSerialized((string)$this->userId, [
			'scope' => $scope,
			'status' => $status,
			'typeId' => $typeId,
			'from' => $from,
			'to' => $to,
			'employeeUid' => $employeeUid,
		], $limit, $offset));
	}

	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		return $this->handle(fn () => $this->service->getDetail((string)$this->userId, $id));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function create(int $typeId, string $startDate, string $endDate, ?float $workingDays = null, ?string $reason = null, ?string $attachmentNote = null, ?string $employeeUid = null, ?string $replacementUid = null, ?bool $disability = null): DataResponse {
		return $this->handle(fn () => $this->service->create((string)$this->userId, [
			'typeId' => $typeId,
			'startDate' => $startDate,
			'endDate' => $endDate,
			'workingDays' => $workingDays,
			'reason' => $reason,
			'attachmentNote' => $attachmentNote,
			'employeeUid' => $employeeUid,
			'replacementUid' => $replacementUid,
			'disability' => $disability,
		])->jsonSerialize());
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function update(int $id, ?int $typeId = null, ?string $startDate = null, ?string $endDate = null, ?string $reason = null, ?string $attachmentNote = null, ?float $workingDays = null, ?string $replacementUid = null, ?bool $disability = null): DataResponse {
		$data = array_filter([
			'typeId' => $typeId,
			'startDate' => $startDate,
			'endDate' => $endDate,
			'workingDays' => $workingDays,
		], static fn ($v) => $v !== null);
		// reason/attachmentNote/replacementUid may be intentionally cleared, so pass explicitly.
		if ($reason !== null) {
			$data['reason'] = $reason;
		}
		if ($attachmentNote !== null) {
			$data['attachmentNote'] = $attachmentNote;
		}
		if ($replacementUid !== null) {
			$data['replacementUid'] = $replacementUid;
		}
		if ($disability !== null) {
			$data['disability'] = $disability;
		}
		return $this->handle(fn () => $this->service->serializeForActor((string)$this->userId, $this->service->update((string)$this->userId, $id, $data)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function cancel(int $id): DataResponse {
		return $this->handle(fn () => $this->service->serializeForActor((string)$this->userId, $this->service->cancel((string)$this->userId, $id)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function approve(int $id, ?string $comment = null): DataResponse {
		return $this->handle(fn () => $this->service->serializeForActor((string)$this->userId, $this->service->approve((string)$this->userId, $id, $comment)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function reject(int $id, string $comment): DataResponse {
		return $this->handle(fn () => $this->service->serializeForActor((string)$this->userId, $this->service->reject((string)$this->userId, $id, $comment)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function addComment(int $id, string $body): DataResponse {
		return $this->handle(fn () => $this->service->addComment((string)$this->userId, $id, $body)->jsonSerialize());
	}
}
