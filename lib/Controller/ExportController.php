<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Service\ClockService;
use OCA\Absence\Service\ExportService;
use OCA\Absence\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

class ExportController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private ExportService $service,
		private PermissionService $permission,
		private ClockService $clock,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function requests(string $from, string $to, ?string $group = null): DataResponse|DataDownloadResponse {
		if (!$this->permission->isHr((string)$this->userId)) {
			return new DataResponse(['message' => $this->l->t('HR role required')], Http::STATUS_FORBIDDEN);
		}
		$export = $this->service->requestsCsv($from, $to, $group);
		return new DataDownloadResponse($export['content'], $export['filename'], 'text/csv');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function balances(?int $year = null, ?string $group = null): DataResponse|DataDownloadResponse {
		if (!$this->permission->isHr((string)$this->userId)) {
			return new DataResponse(['message' => $this->l->t('HR role required')], Http::STATUS_FORBIDDEN);
		}
		$export = $this->service->balancesCsv($year ?? $this->clock->userYear(), $group);
		return new DataDownloadResponse($export['content'], $export['filename'], 'text/csv');
	}
}
