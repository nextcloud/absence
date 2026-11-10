<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Db\Holiday;
use OCA\Absence\Exception\ValidationException;
use OCA\Absence\Service\DateRangeTrait;
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
	use DateRangeTrait;

	// The request dialog asks for last year to next year in one call.
	private const MAX_RANGE_DAYS = 1096;
	private const MIN_YEAR = 2000;
	private const MAX_YEAR = 2100;

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
	#[UserRateLimit(limit: 60, period: 60)]
	public function index(int $year, ?string $region = null): DataResponse {
		return $this->handle(function () use ($year, $region) {
			if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
				throw new ValidationException($this->l->t('The year must be between %1$d and %2$d.', [self::MIN_YEAR, self::MAX_YEAR]));
			}
			return array_map(
				fn (Holiday $h): array => [
					...$h->jsonSerialize(),
					'createdByName' => $this->name($h->getCreatedBy()),
					'updatedByName' => $this->name($h->getUpdatedBy()),
				],
				$this->service->listForYear($year, $region === '' ? null : $region),
			);
		});
	}

	/**
	 * The recent changes to the list, with who made them.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function history(): DataResponse {
		return $this->handle(fn () => array_map(
			fn (array $change): array => [...$change, 'actorName' => $this->name($change['actorUid'])],
			$this->service->history(),
		));
	}

	/**
	 * The days someone with this region code gets, for the personal settings and the
	 * working-days prefill.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function applying(string $code, string $from, string $to): DataResponse {
		return $this->handle(function () use ($code, $from, $to) {
			[$from, $to] = $this->assertValidRange($from, $to, self::MAX_RANGE_DAYS);
			return array_map(
				static fn (Holiday $h): array => $h->jsonSerialize(),
				$this->service->forRegions($code === '' ? [] : [$code], $from, $to),
			);
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function regions(): DataResponse {
		return $this->handle(fn () => $this->service->regions());
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
	 * Imports a JSON list, or with $dryRun only says what it would add. HR only, even
	 * when everyone may edit single holidays.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function import(string $json, bool $dryRun = false): DataResponse {
		return $this->handle(function () use ($json, $dryRun) {
			$this->permission->assertHr((string)$this->userId);
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
