<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Db\Holiday;
use OCA\Absence\Service\HolidayService;
use OCA\Absence\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Public holidays (§7.2): everyone reads them; HR maintains them, and so does
 * everyone else once HR or an admin allows it.
 */
class HolidayController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private HolidayService $service,
		private PermissionService $permission,
		private IL10N $l,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $year, ?string $region = null): DataResponse {
		return $this->handle(fn () => array_map(
			fn (Holiday $h): array => [
				...$h->jsonSerialize(),
				'createdByName' => $this->name($h->getCreatedBy()),
				'updatedByName' => $this->name($h->getUpdatedBy()),
			],
			$this->service->listForYear($year, $region === '' ? null : $region),
		));
	}

	/**
	 * The recent changes to the list, with who made them.
	 */
	#[NoAdminRequired]
	public function history(): DataResponse {
		return $this->handle(fn () => array_map(
			fn (array $change): array => [...$change, 'actorName' => $this->name($change['actorUid'])],
			$this->service->history(),
		));
	}

	/**
	 * HR lets everyone edit the list, or only HR.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function editing(bool $everyone): DataResponse {
		return $this->handle(function () use ($everyone) {
			$this->permission->assertHr((string)$this->userId);
			$this->service->setEveryoneCanEdit($everyone, (string)$this->userId);
			$this->logger->info('Absence action: holidays_editing', ['app' => 'absence', 'action' => 'holidays_editing', 'actor' => $this->userId, 'everyone' => $everyone]);
			return ['everyone' => $everyone];
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $date, string $region, string $label, bool $recurring = false): DataResponse {
		return $this->handle(function () use ($date, $region, $label, $recurring) {
			$this->permission->assertCanEditHolidays((string)$this->userId);
			$holiday = $this->service->create($date, $region, $label, $recurring, $this->userId);
			$this->log('holiday_created', $holiday);
			return $holiday->jsonSerialize();
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(int $id, string $date, string $region, string $label, bool $recurring = false): DataResponse {
		return $this->handle(function () use ($id, $date, $region, $label, $recurring) {
			$this->permission->assertCanEditHolidays((string)$this->userId);
			$holiday = $this->service->update($id, $date, $region, $label, $recurring, $this->userId);
			$this->log('holiday_updated', $holiday);
			return $holiday->jsonSerialize();
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id) {
			$this->permission->assertCanEditHolidays((string)$this->userId);
			$this->log('holiday_deleted', $this->service->delete($id, $this->userId));
			return [];
		});
	}

	/**
	 * Imports a JSON list, or with $dryRun only says what it would add.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function import(string $json, bool $dryRun = false): DataResponse {
		return $this->handle(function () use ($json, $dryRun) {
			$this->permission->assertCanEditHolidays((string)$this->userId);
			$result = $dryRun ? $this->service->plan($json) : $this->service->import($json, $this->userId);
			if (!$dryRun) {
				$this->logger->info('Absence action: holidays_imported', ['app' => 'absence', 'action' => 'holidays_imported', 'actor' => $this->userId, 'added' => count($result['new']), 'existing' => $result['existing']]);
			}
			return $result;
		});
	}

	private function name(?string $uid): ?string {
		return $uid === null ? null : ($this->userManager->getDisplayName($uid) ?? $uid);
	}

	private function log(string $action, Holiday $holiday): void {
		$this->logger->info('Absence action: ' . $action, ['app' => 'absence', 'action' => $action, 'actor' => $this->userId, 'holidayId' => $holiday->getId(), 'region' => $holiday->getRegion(), 'date' => $holiday->getDate()]);
	}
}
