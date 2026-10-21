<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Service\EmployeeService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\ProfileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ProfileController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private ProfileService $profiles,
		private EmployeeService $employees,
		private PermissionService $permission,
		private LoggerInterface $logger,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	/** The person themselves, their manager and HR. */
	#[NoAdminRequired]
	public function show(string $uid): DataResponse {
		return $this->handle(fn () => $this->profiles->get((string)$this->userId, $uid));
	}

	/**
	 * HR enters someone's start and end date and working pattern.
	 *
	 * @param list<array{from?: mixed, weekdays?: mixed, percent?: mixed}> $periods
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function updateContract(string $uid, ?string $start = null, ?string $end = null, array $periods = []): DataResponse {
		return $this->handle(function () use ($uid, $start, $end, $periods) {
			$this->permission->assertHr((string)$this->userId);
			$contract = $this->employees->saveContract($uid, $start, $end, $periods, $this->userId);
			$this->logger->info('Absence action: contract_changed', [
				'app' => 'absence',
				'action' => 'contract_changed',
				'actor' => $this->userId,
				'employee' => $uid,
			]);
			return $contract;
		});
	}
}
