<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Controller;

use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Exception\ForbiddenException;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\PermissionService;
use OCA\Absence\Service\ReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

class BalanceController extends Controller {
	use ApiControllerTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private ?string $userId,
		private BalanceService $balanceService,
		private ReportService $reportService,
		private PermissionService $permission,
		private LeaveTypeMapper $leaveTypeMapper,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function mine(?int $year = null): DataResponse {
		return $this->handle(fn () => $this->withheldForViewer($this->balanceService->getBalance((string)$this->userId, $year)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function forEmployee(string $uid, ?int $year = null): DataResponse {
		return $this->handle(function () use ($uid, $year) {
			if (!$this->permission->canViewBalanceOf((string)$this->userId, $uid)) {
				throw new ForbiddenException($this->l->t('Not allowed to view this balance'));
			}
			return $this->withheldForViewer($this->balanceService->getBalance($uid, $year));
		});
	}

	/**
	 * Balances of the caller's own direct reports. Not HR-gated: the manager
	 * relationship itself is the permission ({@see PermissionService::canViewBalanceOf()}
	 * already grants a manager each report's balance one by one; this is the same
	 * data for the same people, in one call). For a non-manager the list is empty.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function team(?int $year = null): DataResponse {
		return $this->handle(function () use ($year): array {
			$rows = $this->reportService->teamBalances(
				(string)$this->userId,
				$year ?? $this->balanceService->currentYear(),
			);
			return $this->stripHrOnlyRows($rows);
		});
	}

	/**
	 * Balance rows of confidential leave types (§5.7) carry the category in
	 * their labels, so they are stripped for non-HR viewers — including the
	 * employee's own balance view. Their absence changes no number: confidential
	 * types never count against a balance.
	 *
	 * @param array{employeeUid:string,balances:list<array<string,mixed>>} $balance
	 * @return array{employeeUid:string,balances:list<array<string,mixed>>}
	 */
	private function withheldForViewer(array $balance): array {
		$balance['balances'] = $this->stripHrOnlyRows($balance['balances']);
		return $balance;
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return list<array<string,mixed>>
	 */
	private function stripHrOnlyRows(array $rows): array {
		if ($this->permission->isHr((string)$this->userId)) {
			return $rows;
		}
		$hrOnlyIds = $this->leaveTypeMapper->hrOnlyTypeIds();
		return array_values(array_filter(
			$rows,
			static fn (array $row): bool => !in_array($row['typeId'] ?? null, $hrOnlyIds, true),
		));
	}
}
