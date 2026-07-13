<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\ReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
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
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function balances(?int $year = null, ?string $group = null): DataResponse {
		return $this->handle(function () use ($year, $group) {
			$this->permission->assertHr((string)$this->userId);
			return $this->service->balancesReport($year ?? (int)date('Y'), $group);
		});
	}

	#[NoAdminRequired]
	public function trends(string $from, string $to): DataResponse {
		return $this->handle(function () use ($from, $to) {
			$this->permission->assertHr((string)$this->userId);
			return $this->service->trends($from, $to);
		});
	}
}
