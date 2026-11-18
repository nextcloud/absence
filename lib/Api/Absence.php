<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Api;

/**
 * Read-only view of a leave request for other apps.
 *
 * The type follows the shared-calendar visibility setting: `typeKey` and
 * `typeLabel` are only set when the admin chose to show leave types there
 * (`reveal`) and the type is not a confidential category (§5.7). Otherwise both
 * are null and the consumer only learns that the person is away: show that as
 * a neutral "Absent", never as a guess at the reason.
 *
 * Since 1.0.0-beta.3 the type is null unless the setting reveals it, and the
 * `confidential` property is gone.
 *
 * @since 1.0.0
 */
final class Absence implements \JsonSerializable {
	/** Approved, or approved with a withdrawal pending: the person is off */
	public const STATUS_APPROVED = 'approved';
	/** Waiting for a decision (pending or escalated) */
	public const STATUS_PENDING = 'pending';
	/** Rejected or cancelled, only ever sent with a change event */
	public const STATUS_CLOSED = 'closed';

	public function __construct(
		public readonly int $id,
		public readonly string $userId,
		/** Y-m-d, first day off */
		public readonly string $startDate,
		/** Y-m-d, last day off (inclusive) */
		public readonly string $endDate,
		public readonly float $workingDays,
		public readonly string $status,
		public readonly ?string $typeKey,
		public readonly ?string $typeLabel,
	) {
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'startDate' => $this->startDate,
			'endDate' => $this->endDate,
			'workingDays' => $this->workingDays,
			'status' => $this->status,
			'typeKey' => $this->typeKey,
			'typeLabel' => $this->typeLabel,
		];
	}
}
