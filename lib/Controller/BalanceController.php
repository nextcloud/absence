<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class BalanceController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private BalanceService $balanceService,
		private PermissionService $permission,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function mine(?int $year = null): DataResponse {
		return $this->handle(fn () => $this->balanceService->getBalance((string)$this->userId, $year));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function forEmployee(string $uid, ?int $year = null): DataResponse {
		return $this->handle(function () use ($uid, $year) {
			if (!$this->permission->canViewBalanceOf((string)$this->userId, $uid)) {
				throw new ForbiddenException('Not allowed to view this balance');
			}
			return $this->balanceService->getBalance($uid, $year);
		});
	}
}
