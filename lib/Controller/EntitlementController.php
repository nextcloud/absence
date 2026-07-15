<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Service\EntitlementService;
use OCA\Absence\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class EntitlementController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private EntitlementService $service,
		private PermissionService $permission,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(string $employeeUid, ?int $year = null): DataResponse {
		return $this->handle(function () use ($employeeUid, $year) {
			$this->permission->assertHr((string)$this->userId);
			return array_map(static fn ($e) => $e->jsonSerialize(), $this->service->listForEmployee($employeeUid, $year));
		});
	}

	#[NoAdminRequired]
	public function create(string $employeeUid, int $year, int $typeId, ?float $baseDays = null, ?float $carryOverDays = null, ?float $manualAdjustment = null, ?string $adjustmentNote = null): DataResponse {
		return $this->handle(function () use ($employeeUid, $year, $typeId, $baseDays, $carryOverDays, $manualAdjustment, $adjustmentNote) {
			$this->permission->assertHr((string)$this->userId);
			$data = array_filter([
				'baseDays' => $baseDays,
				'carryOverDays' => $carryOverDays,
				'manualAdjustment' => $manualAdjustment,
				'adjustmentNote' => $adjustmentNote,
			], static fn ($v) => $v !== null);
			return $this->service->setForEmployee((string)$this->userId, $employeeUid, $year, $typeId, $data)->jsonSerialize();
		});
	}

	#[NoAdminRequired]
	public function update(int $id, ?float $baseDays = null, ?float $carryOverDays = null, ?float $manualAdjustment = null, ?string $adjustmentNote = null): DataResponse {
		return $this->handle(function () use ($id, $baseDays, $carryOverDays, $manualAdjustment, $adjustmentNote) {
			$this->permission->assertHr((string)$this->userId);
			$data = array_filter([
				'baseDays' => $baseDays,
				'carryOverDays' => $carryOverDays,
				'manualAdjustment' => $manualAdjustment,
				'adjustmentNote' => $adjustmentNote,
			], static fn ($v) => $v !== null);
			return $this->service->update((string)$this->userId, $id, $data)->jsonSerialize();
		});
	}

	#[NoAdminRequired]
	public function bulk(int $year, int $typeId, float $baseDays, ?string $group = null): DataResponse {
		return $this->handle(function () use ($year, $typeId, $baseDays, $group) {
			$this->permission->assertHr((string)$this->userId);
			return ['affected' => $this->service->bulkSet($year, $typeId, $baseDays, $group)];
		});
	}
}
