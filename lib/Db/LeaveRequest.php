<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getEmployeeUid()
 * @method void setEmployeeUid(string $employeeUid)
 * @method string|null getManagerUid()
 * @method void setManagerUid(?string $managerUid)
 * @method int getTypeId()
 * @method void setTypeId(int $typeId)
 * @method string getStartDate()
 * @method void setStartDate(string $startDate)
 * @method string getEndDate()
 * @method void setEndDate(string $endDate)
 * @method float getWorkingDays()
 * @method void setWorkingDays(float $workingDays)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method string|null getReplacementUid()
 * @method void setReplacementUid(?string $replacementUid)
 * @method string|null getAttachmentNote()
 * @method void setAttachmentNote(?string $attachmentNote)
 * @method string|null getDecidedBy()
 * @method void setDecidedBy(?string $decidedBy)
 * @method \DateTime|null getDecidedAt()
 * @method void setDecidedAt(?\DateTime $decidedAt)
 * @method string|null getDecisionComment()
 * @method void setDecisionComment(?string $decisionComment)
 * @method bool getEscalated()
 * @method void setEscalated(bool $escalated)
 * @method int|null getSupersedesId()
 * @method void setSupersedesId(?int $supersedesId)
 * @method string|null getCalendarEventUri()
 * @method void setCalendarEventUri(?string $calendarEventUri)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class LeaveRequest extends Entity implements \JsonSerializable {
	// Status enum values (see spec §4)
	public const STATUS_PENDING = 'PENDING';
	public const STATUS_ESCALATED = 'ESCALATED';
	public const STATUS_APPROVED = 'APPROVED';
	public const STATUS_REJECTED = 'REJECTED';
	public const STATUS_CANCELLED = 'CANCELLED';
	public const STATUS_WITHDRAWAL_PENDING = 'WITHDRAWAL_PENDING';

	/** Statuses that occupy the "pending" balance bucket (§4.2). */
	public const PENDING_STATUSES = [self::STATUS_PENDING, self::STATUS_ESCALATED, self::STATUS_WITHDRAWAL_PENDING];
	/** Statuses that occupy the "used" balance bucket (§4.2). */
	public const USED_STATUSES = [self::STATUS_APPROVED];
	/** Terminal statuses that can no longer transition. */
	public const TERMINAL_STATUSES = [self::STATUS_REJECTED, self::STATUS_CANCELLED];
	/** Non-terminal statuses used for overlap detection. */
	public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_ESCALATED, self::STATUS_APPROVED, self::STATUS_WITHDRAWAL_PENDING];

	protected string $employeeUid = '';
	protected ?string $managerUid = null;
	protected int $typeId = 0;
	protected string $startDate = '';
	protected string $endDate = '';
	protected float $workingDays = 0.0;
	protected string $status = self::STATUS_PENDING;
	protected ?string $reason = null;
	protected ?string $replacementUid = null;
	protected ?string $attachmentNote = null;
	protected ?string $decidedBy = null;
	protected ?\DateTime $decidedAt = null;
	protected ?string $decisionComment = null;
	protected bool $escalated = false;
	protected ?int $supersedesId = null;
	protected ?string $calendarEventUri = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('typeId', 'integer');
		$this->addType('workingDays', 'float');
		$this->addType('decidedAt', 'datetime');
		$this->addType('escalated', 'boolean');
		$this->addType('supersedesId', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'employeeUid' => $this->employeeUid,
			'managerUid' => $this->managerUid,
			'typeId' => $this->typeId,
			'startDate' => $this->startDate,
			'endDate' => $this->endDate,
			'workingDays' => $this->workingDays,
			'status' => $this->status,
			'reason' => $this->reason,
			'replacementUid' => $this->replacementUid,
			'attachmentNote' => $this->attachmentNote,
			'decidedBy' => $this->decidedBy,
			'decidedAt' => $this->decidedAt?->format(\DateTimeInterface::ATOM),
			'decisionComment' => $this->decisionComment,
			'escalated' => $this->escalated,
			'supersedesId' => $this->supersedesId,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
