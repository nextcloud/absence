<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Dashboard;

use OCA\Absence\Db\LeaveRequest;
use OCA\Absence\Db\LeaveRequestMapper;
use OCA\Absence\Db\LeaveTypeMapper;
use OCA\Absence\Service\BalanceService;
use OCA\Absence\Service\ManagerResolver;
use OCA\Absence\Service\PermissionService;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * Dashboard widget (spec §15.6). Role-aware:
 *  - every employee sees their own balance and upcoming/pending leave;
 *  - line managers additionally see requests from their team awaiting a decision;
 *  - HR sees the escalated queue across the whole company.
 *
 * Implemented as an API widget so the core Dashboard renders the item list — no
 * custom frontend bundle required.
 */
class AbsenceWidget implements IAPIWidget, IAPIWidgetV2, IIconWidget {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
		private IUserManager $userManager,
		private LeaveRequestMapper $requestMapper,
		private LeaveTypeMapper $leaveTypeMapper,
		private BalanceService $balanceService,
		private PermissionService $permission,
		private ManagerResolver $managerResolver,
	) {
	}

	public function getId(): string {
		return 'absence';
	}

	public function getTitle(): string {
		return $this->l->t('Absence');
	}

	public function getOrder(): int {
		return 20;
	}

	public function getIconClass(): string {
		return 'icon-absence';
	}

	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('absence', 'app-dark.svg'));
	}

	public function getUrl(): ?string {
		return $this->urlGenerator->linkToRouteAbsolute('absence.page.index');
	}

	public function load(): void {
		// Nothing to enqueue: the core Dashboard renders the API items.
	}

	/**
	 * @return WidgetItem[]
	 */
	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		return $this->buildItems($userId, $limit);
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$items = $this->buildItems($userId, $limit);
		return new WidgetItems(
			$items,
			$this->l->t('Nothing booked yet — time to plan a break? 🌴'),
		);
	}

	/**
	 * @return WidgetItem[]
	 */
	private function buildItems(string $userId, int $limit): array {
		$appIcon = $this->getIconUrl();
		$base = $this->urlGenerator->linkToRouteAbsolute('absence.page.index');
		$isHr = $this->permission->isHr($userId);
		$isManager = $this->managerResolver->getDirectReports($userId) !== [];
		$types = $this->typeLabels();

		$items = [];

		// 1. Own balance summary (all employees).
		$summary = $this->balanceSummary($userId);
		if ($summary !== null) {
			$items[] = new WidgetItem(
				$summary['title'],
				$summary['subtitle'],
				$base . '#/my',
				$appIcon,
				'balance',
			);
		}

		// 2. Own upcoming / pending leave (all employees).
		foreach ($this->ownUpcoming($userId) as $r) {
			$items[] = new WidgetItem(
				($types[$r->getTypeId()] ?? $this->l->t('Leave')) . ' · ' . $this->range($r),
				$this->statusLabel($r->getStatus()),
				$base . '#/requests/' . $r->getId(),
				$appIcon,
				'own-' . $r->getId(),
			);
		}

		// 3. Manager: team requests awaiting their decision.
		if ($isManager) {
			foreach ($this->requestMapper->findPendingForManager($userId) as $r) {
				$items[] = new WidgetItem(
					$this->displayName($r->getEmployeeUid()) . ' · ' . ($types[$r->getTypeId()] ?? $this->l->t('Leave')),
					$this->l->t('Awaiting your approval · %s', [$this->range($r)]),
					$base . '#/requests/' . $r->getId(),
					$appIcon,
					'approve-' . $r->getId(),
				);
			}
		}

		// 4. HR: escalated queue across the company.
		if ($isHr) {
			foreach ($this->requestMapper->findEscalated() as $r) {
				$items[] = new WidgetItem(
					$this->displayName($r->getEmployeeUid()) . ' · ' . ($types[$r->getTypeId()] ?? $this->l->t('Leave')),
					$this->l->t('Escalated — needs HR · %s', [$this->range($r)]),
					$base . '#/requests/' . $r->getId(),
					$appIcon,
					'hr-' . $r->getId(),
				);
			}
		}

		return array_slice($items, 0, $limit);
	}

	/**
	 * @return array{title:string,subtitle:string}|null
	 */
	private function balanceSummary(string $userId): ?array {
		foreach ($this->balanceService->getBalance($userId)['balances'] as $row) {
			if ($row['typeKey'] === 'annual' && $row['remaining'] !== null) {
				$remaining = $this->formatDays((float)$row['remaining']);
				$pending = (float)$row['pending'];
				$subtitle = $pending > 0
					? $this->l->t('%1$s used · %2$s pending', [$this->formatDays((float)$row['used']), $this->formatDays($pending)])
					: $this->l->t('%s used', [$this->formatDays((float)$row['used'])]);
				return [
					'title' => $this->l->t('%s days of annual leave left', [$remaining]),
					'subtitle' => $subtitle,
				];
			}
		}
		return null;
	}

	/**
	 * Non-terminal own requests that have not yet ended, soonest first.
	 *
	 * @return LeaveRequest[]
	 */
	private function ownUpcoming(string $userId): array {
		$today = date('Y-m-d');
		$requests = array_filter(
			$this->requestMapper->findAllForEmployee($userId),
			static fn (LeaveRequest $r): bool => in_array($r->getStatus(), LeaveRequest::ACTIVE_STATUSES, true) && $r->getEndDate() >= $today,
		);
		usort($requests, static fn (LeaveRequest $a, LeaveRequest $b): int => $a->getStartDate() <=> $b->getStartDate());
		return $requests;
	}

	private function range(LeaveRequest $r): string {
		return $r->getStartDate() === $r->getEndDate()
			? $r->getStartDate()
			: $r->getStartDate() . ' – ' . $r->getEndDate();
	}

	private function statusLabel(string $status): string {
		return match ($status) {
			LeaveRequest::STATUS_PENDING => $this->l->t('Pending'),
			LeaveRequest::STATUS_ESCALATED => $this->l->t('With HR'),
			LeaveRequest::STATUS_APPROVED => $this->l->t('Approved'),
			LeaveRequest::STATUS_WITHDRAWAL_PENDING => $this->l->t('Withdrawal pending'),
			LeaveRequest::STATUS_REJECTED => $this->l->t('Declined'),
			LeaveRequest::STATUS_CANCELLED => $this->l->t('Cancelled'),
			default => $status,
		};
	}

	/**
	 * @return array<int,string>
	 */
	private function typeLabels(): array {
		$labels = [];
		foreach ($this->leaveTypeMapper->findAll() as $type) {
			$labels[$type->getId()] = $type->getIcon() . ' ' . $type->getLabel();
		}
		return $labels;
	}

	private function displayName(string $uid): string {
		$user = $this->userManager->get($uid);
		return $user !== null ? $user->getDisplayName() : $uid;
	}

	private function formatDays(float $v): string {
		return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
	}
}
