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
 * @method string getActorUid()
 * @method void setActorUid(string $actorUid)
 * @method string getEventType()
 * @method void setEventType(string $eventType)
 * @method string|null getDetail()
 * @method void setDetail(?string $detail)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class RequestEvent extends Entity implements \JsonSerializable {
	protected int $requestId = 0;
	protected string $actorUid = '';
	protected string $eventType = '';
	protected ?string $detail = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('requestId', 'integer');
		$this->addType('createdAt', 'datetime');
	}

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
