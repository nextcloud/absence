<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Absence\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One immutable entry in a request's history timeline (spec §15.1 History tab).
 *
 * @method int getRequestId()
 * @method void setRequestId(int $requestId)
 * @method ?string getActorUid() null for an import from occ, and once the actor's account is deleted
 * @method void setActorUid(?string $actorUid)
 * @method string getEventType()
 * @method void setEventType(string $eventType)
 * @method string|null getDetail()
 * @method void setDetail(?string $detail)
 * @method string|null getHrDetail()
 * @method void setHrDetail(?string $hrDetail)
 * @method bool getConfidential()
 * @method void setConfidential(bool $confidential)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class RequestEvent extends Entity implements \JsonSerializable {
	/** Actor of the steps no person took: the escalation job. */
	public const ACTOR_SYSTEM = 'system';
	/** A request imported as past leave (§6.5); from occ it has no actor. */
	public const TYPE_IMPORTED = 'request_imported';

	protected int $requestId = 0;
	protected ?string $actorUid = null;
	protected string $eventType = '';
	protected ?string $detail = null;
	/** The detail with the free text it leaves out (reasons, notes, comments), for HR alone (§3.7). Null when there is none. */
	protected ?string $hrDetail = null;
	/** Written while the request was confidential (§5.7): shown to HR alone, whatever the request's type is later. */
	protected bool $confidential = false;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('requestId', 'integer');
		$this->addType('confidential', 'boolean');
		$this->addType('createdAt', 'datetime');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'requestId' => $this->requestId,
			'actorUid' => $this->actorUid,
			'eventType' => $this->eventType,
			'detail' => $this->detail,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
