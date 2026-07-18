<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Service\CoverageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Events for the in-app who's-off calendar / timeline (§8, §15.2).
 */
class CalendarController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private CoverageService $service,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(string $from, string $to, string $scope = 'team'): DataResponse {
		return $this->handle(function () use ($from, $to, $scope) {
			$uids = $this->service->resolveScopeUids((string)$this->userId, $scope);
			$coverage = $this->service->getCoverage($uids, $from, $to, null, (string)$this->userId);
			return ['events' => $coverage['events']];
		});
	}
}
