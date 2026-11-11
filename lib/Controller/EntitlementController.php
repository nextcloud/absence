<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Service\EntitlementService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\ProRataService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class EntitlementController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private EntitlementService $service,
		private ProRataService $proRata,
		private PermissionService $permission,
		private IL10N $l,
		private LoggerInterface $logger,
		private IUserManager $userManager,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function index(string $employeeUid, ?int $year = null): DataResponse {
		return $this->handle(function () use ($employeeUid, $year) {
			$this->permission->assertHr((string)$this->userId);
			return array_map(static fn ($e) => $e->jsonSerialize(), $this->service->listForEmployee($employeeUid, $year));
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function create(string $employeeUid, int $year, int $typeId, ?float $baseDays = null, ?float $carryOverDays = null, ?float $manualAdjustment = null, ?float $adjustmentDelta = null, ?string $adjustmentNote = null): DataResponse {
		return $this->handle(function () use ($employeeUid, $year, $typeId, $baseDays, $carryOverDays, $manualAdjustment, $adjustmentDelta, $adjustmentNote) {
			$this->permission->assertHr((string)$this->userId);
			$data = array_filter([
				'baseDays' => $baseDays,
				'carryOverDays' => $carryOverDays,
				'manualAdjustment' => $manualAdjustment,
				'adjustmentDelta' => $adjustmentDelta,
				'adjustmentNote' => $adjustmentNote,
			], static fn ($v) => $v !== null);
			return $this->service->setForEmployee((string)$this->userId, $employeeUid, $year, $typeId, $data)->jsonSerialize();
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function update(int $id, ?float $baseDays = null, ?float $carryOverDays = null, ?float $manualAdjustment = null, ?float $adjustmentDelta = null, ?string $adjustmentNote = null): DataResponse {
		return $this->handle(function () use ($id, $baseDays, $carryOverDays, $manualAdjustment, $adjustmentDelta, $adjustmentNote) {
			$this->permission->assertHr((string)$this->userId);
			$data = array_filter([
				'baseDays' => $baseDays,
				'carryOverDays' => $carryOverDays,
				'manualAdjustment' => $manualAdjustment,
				'adjustmentDelta' => $adjustmentDelta,
				'adjustmentNote' => $adjustmentNote,
			], static fn ($v) => $v !== null);
			return $this->service->update((string)$this->userId, $id, $data)->jsonSerialize();
		});
	}

	/**
	 * Who changed this entitlement, which figure, from what to what, and why (§6.1).
	 * HR only, like every other entitlement endpoint.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function history(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->permission->assertHr((string)$this->userId);
			// One lookup per person, however many changes they made.
			$names = [];
			$history = [];
			foreach ($this->service->historyFor($id) as $event) {
				// A change made without a user (occ) has no actor to name
				$uid = $event->getActorUid();
				if ($uid !== null) {
					$names[$uid] ??= $this->userManager->getDisplayName($uid) ?? $uid;
				}
				$history[] = [...$event->jsonSerialize(), 'actorName' => $uid !== null ? $names[$uid] : null];
			}
			return $history;
		});
	}

	/**
	 * How many employees have this many days as base this year: the ones a
	 * changed default can be handed on to (§6.1).
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function onDefault(float $days): DataResponse {
		return $this->handle(function () use ($days) {
			$this->permission->assertCanManageSettings((string)$this->userId);
			return $this->service->countOnDefault($days);
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function applyDefault(float $from, float $to): DataResponse {
		return $this->handle(function () use ($from, $to) {
			$this->permission->assertCanManageSettings((string)$this->userId);
			return ['affected' => $this->service->applyDefault((string)$this->userId, $from, $to)];
		});
	}

	/**
	 * What the contract of this employee would give them for the year (§6.6),
	 * for HR to take over or not.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function proRata(string $employeeUid, int $year): DataResponse {
		return $this->handle(function () use ($employeeUid, $year) {
			$this->permission->assertHr((string)$this->userId);
			return $this->proRata->suggest($employeeUid, $year);
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function bulk(int $year, int $typeId, float $baseDays, ?string $group = null): DataResponse {
		return $this->handle(function () use ($year, $typeId, $baseDays, $group) {
			$this->permission->assertHr((string)$this->userId);
			return ['affected' => $this->service->bulkSet((string)$this->userId, $year, $typeId, $baseDays, $group)];
		});
	}
}
