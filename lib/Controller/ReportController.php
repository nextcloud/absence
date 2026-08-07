<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\ReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ReportController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private ReportService $service,
		private PermissionService $permission,
		private ClockService $clock,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function balances(?int $year = null, ?string $group = null): DataResponse {
		return $this->handle(function () use ($year, $group) {
			$this->permission->assertHr((string)$this->userId);
			return $this->service->balancesReport($year ?? $this->clock->userYear(), $group);
		});
	}

	/**
	 * Sick-leave overview: every employee ranked by days lost. HR only — this is
	 * health-adjacent data about named people, so unlike the coverage views it is
	 * never visible to line managers.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function sickLeave(?int $year = null, ?string $group = null, ?int $typeId = null): DataResponse {
		return $this->handle(function () use ($year, $group, $typeId) {
			$this->permission->assertHr((string)$this->userId);
			return $this->service->sickLeaveReport($year ?? $this->clock->userYear(), $group, $typeId);
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function trends(string $from, string $to): DataResponse {
		return $this->handle(function () use ($from, $to) {
			$this->permission->assertHr((string)$this->userId);
			return $this->service->trends($from, $to);
		});
	}
}
